<?php

namespace IXP\Services\Akvorado;

/*
 * Copyright (C) 2026 EdgeIX Pty Ltd.
 *
 * Akvorado flow collector API client for IXP Manager.
 *
 * Queries the Akvorado REST API for sFlow-based traffic data using
 * MAC addresses + VLAN tags to identify peer-to-peer traffic flows.
 */

use Log;

use Illuminate\Support\Facades\{Cache, DB, Http};

use IXP\Models\{
    Customer,
    Vlan,
    VlanInterface as VlanInterfaceModel
};

use IXP\Models\Aggregators\VlanInterfaceAggregator;

use IXP\Services\Grapher\Graph;

class AkvoradoService
{
    /**
     * Map IXP-Manager graph periods to Akvorado time ranges.
     */
    private const PERIOD_MAP = [
        Graph::PERIOD_DAY   => [ 'range' => '24h',  'points' => 288 ],
        Graph::PERIOD_WEEK  => [ 'range' => '7d',   'points' => 336 ],
        Graph::PERIOD_MONTH => [ 'range' => '30d',  'points' => 360 ],
        Graph::PERIOD_YEAR  => [ 'range' => '365d', 'points' => 365 ],
    ];

    /**
     * Map IXP-Manager protocol constants to Akvorado EType filters.
     */
    private const ETYPE_MAP = [
        Graph::PROTOCOL_IPV4 => 'IPv4',
        Graph::PROTOCOL_IPV6 => 'IPv6',
    ];

    /**
     * @var string Akvorado API base URL
     */
    private string $url;

    /**
     * @var int HTTP timeout in seconds
     */
    private int $timeout;

    /**
     * @var string|null Basic auth username (optional)
     */
    private ?string $authUser;

    /**
     * @var string|null Basic auth password (optional)
     */
    private ?string $authPass;

    public function __construct()
    {
        $this->url      = rtrim( config( 'grapher.backends.akvorado.url', '' ), '/' );
        $this->timeout  = (int) config( 'grapher.backends.akvorado.timeout', 30 );
        $this->authUser = config( 'grapher.backends.akvorado.auth_user' ) ?: null;
        $this->authPass = config( 'grapher.backends.akvorado.auth_pass' ) ?: null;
    }

    /**
     * Get the VLAN tag to use in Akvorado queries for a given VlanInterface.
     *
     * If the VLI has a port-specific vlantag (customer VLAN rewrite),
     * use that — it's what sFlow sees pre-rewrite.
     * Otherwise use the IXP exchange VLAN number (untagged ports).
     */
    public function resolveVlan( VlanInterfaceModel $vli ): int
    {
        if( $vli->vlantag !== null && $vli->vlantag > 0 ) {
            return $vli->vlantag;
        }

        return $vli->vlan->number;
    }

    /**
     * Get configured (static) MAC addresses for a VlanInterface.
     *
     * Returns lowercase colon-separated MACs (e.g. "00:1a:2b:3c:4d:5e").
     *
     * @return string[]
     */
    public function resolveMACs( VlanInterfaceModel $vli ): array
    {
        return $vli->layer2addresses()
            ->pluck( 'mac' )
            ->map( fn( $mac ) => strtolower(
                implode( ':', str_split( str_replace( [ ':', '-', '.' ], '', $mac ), 2 ) )
            ) )
            ->toArray();
    }

    /**
     * Build an Akvorado filter string for a set of MACs.
     *
     * For a single MAC:   SrcMAC = '00:1a:2b:3c:4d:5e'
     * For multiple MACs:  (SrcMAC = '...' OR SrcMAC = '...')
     */
    private function buildMacFilter( string $field, array $macs ): string
    {
        if( count( $macs ) === 1 ) {
            return "{$field} = {$macs[0]}";
        }

        $parts = array_map( fn( $mac ) => "{$field} = {$mac}", $macs );
        return '(' . implode( ' OR ', $parts ) . ')';
    }

    /**
     * Execute multiple queryTimeSeries-style queries concurrently via Http::pool().
     *
     * Query descriptor shape:
     *   [
     *     'key'        => string,   // caller-supplied key for the return map
     *     'filter'     => string,   // Akvorado filter expression
     *     'period'     => string,   // Graph period constant
     *     'units'      => string,   // e.g. 'l3bps'
     *     'dimensions' => string[], // dimensions to group by (may be empty)
     *     'limit'      => int,      // max rows returned (>= expected dimension count)
     *   ]
     *
     * Return: results keyed by descriptor 'key'. Failed queries return [] for that key.
     *
     * Cache hits are served straight from the local file cache; only cache misses
     * hit the network — and they go via one Http::pool() call so wall-clock is
     * bounded by the slowest single query, not the sum.
     *
     * @param  array<int, array<string, mixed>> $descriptors
     * @return array<string, array>
     */
    public function queryTimeSeriesParallel( array $descriptors ): array
    {
        if( empty( $descriptors ) ) {
            return [];
        }

        $results = [];

        // Build cache keys + payloads; separate hits from misses.
        $liveDescriptors = [];
        $now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

        foreach( $descriptors as $desc ) {
            $key      = $desc['key'];
            $period   = $desc['period'] ?? Graph::PERIOD_DAY;
            $timing   = self::PERIOD_MAP[ $period ] ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];
            $filter   = $desc['filter'];
            $units    = $desc['units'] ?? 'l3bps';
            $dimensions = $desc['dimensions'] ?? [];
            $limit    = $desc['limit'] ?? 5;

            // 5-minute cache window, same shape as queryTimeSeries().
            $cacheWindow = (int) floor( time() / 300 );
            $cacheKey    = 'akvorado:' . md5( $filter . $period . $units . json_encode( $dimensions ) . $cacheWindow );

            $cached = Cache::get( $cacheKey );
            if( $cached !== null ) {
                $results[ $key ] = $cached;
                continue;
            }

            // Build the payload once here so pool callbacks stay simple.
            $start = clone $now;
            if( preg_match( '/^(\d+)([hd])$/', $timing['range'], $m ) ) {
                $start->modify( $m[2] === 'h' ? "-{$m[1]} hours" : "-{$m[1]} days" );
            }
            $payload = [
                'start'  => $start->format( 'c' ),
                'end'    => $now->format( 'c' ),
                'filter' => $filter,
                'units'  => $units,
                'points' => $timing['points'],
                'limit'  => $limit,
            ];
            if( !empty( $dimensions ) ) {
                $payload['dimensions'] = $dimensions;
            }

            $liveDescriptors[] = [
                'key'      => $key,
                'cacheKey' => $cacheKey,
                'payload'  => $payload,
                'filter'   => $filter,
                'period'   => $period,
            ];
        }

        if( empty( $liveDescriptors ) ) {
            return $results;
        }

        Log::debug( sprintf( '[Akvorado] queryTimeSeriesParallel: %d live queries (%d cached)',
            count( $liveDescriptors ), count( $results ) ) );

        // Fire all live queries concurrently. Bump timeout above the sequential
        // default because year-period queries can take 15-20s server-side, but
        // in parallel wall-clock is just the slowest one.
        $url        = "{$this->url}/api/v0/console/graph/line";
        $timeout    = max( 30, $this->timeout );
        $authUser   = $this->authUser;
        $authPass   = $this->authPass;

        $responses = Http::pool( function ( $pool ) use ( $liveDescriptors, $url, $timeout, $authUser, $authPass ) {
            return array_map( function ( $desc ) use ( $pool, $url, $timeout, $authUser, $authPass ) {
                // retry(2, 1500) — up to 2 retries with 1.5s between. Akvorado /
                // ClickHouse can return HTTP 500 'Unable to query database' when
                // the concurrent year-range wave overwhelms it; a second attempt
                // after the wave has passed usually succeeds. throw:false lets
                // us handle the final failure ourselves without an exception.
                $req = $pool->as( $desc['key'] )
                    ->timeout( $timeout )
                    ->retry( 2, 1500, function ( $exception, $request ) {
                        // Retry on connection errors and 5xx; don't retry 4xx
                        // (a 400 is a filter bug — retrying won't help).
                        return $exception instanceof \Illuminate\Http\Client\ConnectionException
                            || ( method_exists( $exception, 'response' )
                                 && $exception->response()
                                 && $exception->response()->status() >= 500 );
                    }, throw: false );
                if( $authUser && $authPass ) {
                    $req = $req->withBasicAuth( $authUser, $authPass );
                }
                return $req->post( $url, $desc['payload'] );
            }, $liveDescriptors );
        } );

        // Correlate responses back to descriptors and cache successful ones.
        $descByKey = [];
        foreach( $liveDescriptors as $desc ) {
            $descByKey[ $desc['key'] ] = $desc;
        }

        foreach( $responses as $key => $resp ) {
            $desc = $descByKey[ $key ] ?? null;
            if( !$desc ) {
                continue;
            }

            if( $resp instanceof \Throwable ) {
                Log::warning( '[Akvorado] Pool request threw', [
                    'key'    => $key,
                    'filter' => $desc['filter'],
                    'period' => $desc['period'],
                    'error'  => $resp->getMessage(),
                ] );
                $results[ $key ] = [];
                continue;
            }

            if( !$resp->successful() ) {
                Log::warning( '[Akvorado] Pool query failed', [
                    'key'    => $key,
                    'period' => $desc['period'],
                    'status' => $resp->status(),
                    'body'   => substr( $resp->body(), 0, 500 ),
                ] );
                $results[ $key ] = [];
                continue;
            }

            $data = $resp->json() ?? [];
            Cache::put( $desc['cacheKey'], $data, 300 );
            $results[ $key ] = $data;
        }

        return $results;
    }

    /**
     * Query Akvorado's graph/line API endpoint.
     *
     * @param  string $filter     Akvorado filter expression
     * @param  string $period     Graph period constant (day/week/month/year)
     * @param  string $units      Akvorado units (l3bps, pps, etc.)
     * @param  array  $dimensions Dimensions to group by
     *
     * @return array  Raw API response decoded from JSON
     */
    public function queryTimeSeries(
        string $filter,
        string $period = Graph::PERIOD_DAY,
        string $units  = 'l3bps',
        array  $dimensions = [],
        int    $limit  = 5
    ): array {
        $timing = self::PERIOD_MAP[ $period ] ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];

        // Cache key based on filter + period + units (rounded to 5-min windows)
        $cacheWindow = (int) floor( time() / 300 );
        $cacheKey    = 'akvorado:' . md5( $filter . $period . $units . json_encode( $dimensions ) . $cacheWindow );

        $cached = Cache::get( $cacheKey );
        if( $cached !== null ) {
            Log::debug( "[Akvorado] Cache hit: {$filter} ({$period})" );
            return $cached;
        }

        $now   = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $start = clone $now;

        // Parse range string (e.g. "24h", "7d", "365d")
        if( preg_match( '/^(\d+)([hd])$/', $timing['range'], $m ) ) {
            $start->modify( $m[2] === 'h' ? "-{$m[1]} hours" : "-{$m[1]} days" );
        }

        $payload = [
            'start'      => $start->format( 'c' ),
            'end'        => $now->format( 'c' ),
            'filter'     => $filter,
            'units'      => $units,
            'points'     => $timing['points'],
            'limit'      => $limit,
        ];

        if( !empty( $dimensions ) ) {
            $payload['dimensions'] = $dimensions;
        }

        Log::debug( "[Akvorado] queryTimeSeries: POST {$this->url}/api/v0/console/graph/line", [
            'filter' => $filter, 'units' => $units, 'period' => $period,
        ] );

        try {
            $http = Http::timeout( $this->timeout );

            if( $this->authUser && $this->authPass ) {
                $http = $http->withBasicAuth( $this->authUser, $this->authPass );
            }

            $response = $http->post( "{$this->url}/api/v0/console/graph/line", $payload );

            if( !$response->successful() ) {
                Log::warning( "[Akvorado] API error: HTTP {$response->status()}", [
                    'body' => substr( $response->body(), 0, 500 ),
                ] );
                return [];
            }

            $result = $response->json() ?? [];

            // Cache for 5 minutes
            Cache::put( $cacheKey, $result, 300 );

            return $result;
        } catch( \Exception $e ) {
            Log::warning( "[Akvorado] API request failed: {$e->getMessage()}" );
            return [];
        }
    }

    /**
     * Get peer-to-peer traffic between two VlanInterfaces.
     *
     * Returns data in the grapher standard format:
     *   [[timestamp, avg_in, avg_out, max_in, max_out], ...]
     *
     * "in" = traffic received by source from destination (DstMAC → SrcMAC)
     * "out" = traffic sent by source to destination (SrcMAC → DstMAC)
     */
    public function p2pTraffic(
        VlanInterfaceModel $svli,
        VlanInterfaceModel $dvli,
        string $period   = Graph::PERIOD_DAY,
        string $protocol = Graph::PROTOCOL_IPV4,
        string $category = Graph::CATEGORY_BITS
    ): array {
        $srcMacs = $this->resolveMACs( $svli );
        $dstMacs = $this->resolveMACs( $dvli );

        if( empty( $srcMacs ) || empty( $dstMacs ) ) {
            return [];
        }

        $srcVlan = $this->resolveVlan( $svli );
        $dstVlan = $this->resolveVlan( $dvli );
        $units   = $this->categoryToUnits( $category );

        // Add EType filter for IPv4/IPv6
        $etypeFilter = $this->buildEtypeFilter( $protocol );

        // OUT: traffic from source to destination
        // sFlow sees this with SrcVlan = source's vlantag
        $outFilter = $this->buildMacFilter( 'SrcMAC', $srcMacs )
            . ' AND ' . $this->buildMacFilter( 'DstMAC', $dstMacs )
            . " AND SrcVlan = {$srcVlan}"
            . $etypeFilter;

        // IN: traffic from destination to source
        // sFlow sees this with SrcVlan = destination's vlantag
        $inFilter = $this->buildMacFilter( 'SrcMAC', $dstMacs )
            . ' AND ' . $this->buildMacFilter( 'DstMAC', $srcMacs )
            . " AND SrcVlan = {$dstVlan}"
            . $etypeFilter;

        $outData = $this->queryTimeSeries( $outFilter, $period, $units );
        $inData  = $this->queryTimeSeries( $inFilter,  $period, $units );

        return $this->mergeDirectionalData( $inData, $outData );
    }

    /**
     * Batch-fetch P2P traffic for a source VLI against all destination VLIs.
     *
     * Uses Akvorado's dimension grouping to fetch all peer traffic in just
     * 2 API calls (OUT with DstMAC dimension, IN with SrcMAC dimension)
     * instead of 2 calls per peer.
     *
     * @param  VlanInterfaceModel   $svli     Source VlanInterface
     * @param  iterable             $dstVlis  Collection of destination VlanInterfaces
     * @param  string               $period   Graph period
     * @param  string               $protocol IPv4/IPv6
     * @param  string               $category bits/packets
     *
     * @return array  Keyed by dvli_id => [[timestamp, avg_in, avg_out, max_in, max_out], ...]
     */
    public function p2pBatchTraffic(
        VlanInterfaceModel $svli,
        iterable $dstVlis,
        string $period   = Graph::PERIOD_DAY,
        string $protocol = Graph::PROTOCOL_IPV4,
        string $category = Graph::CATEGORY_BITS
    ): array {
        $srcMacs = $this->resolveMACs( $svli );

        if( empty( $srcMacs ) ) {
            return [];
        }

        $srcVlan     = $this->resolveVlan( $svli );
        $units       = $this->categoryToUnits( $category );
        $etypeFilter = $this->buildEtypeFilter( $protocol );

        // Build MAC → dvli_id mapping
        $macToVli = [];
        $numPeers = 0;

        foreach( $dstVlis as $dvli ) {
            $numPeers++;
            foreach( $this->resolveMACs( $dvli ) as $mac ) {
                $macToVli[ $mac ] = $dvli->id;
            }
        }

        if( empty( $macToVli ) ) {
            return [];
        }

        // Limit must exceed unique MAC count to avoid "Other" bucket swallowing peers
        $limit = max( count( $macToVli ) + 10, $numPeers + 10 );

        // OUT batch: traffic FROM source TO all peers (grouped by DstMAC)
        $outFilter = $this->buildMacFilter( 'SrcMAC', $srcMacs )
            . " AND SrcVlan = {$srcVlan}"
            . $etypeFilter;

        $outData = $this->queryTimeSeries( $outFilter, $period, $units, [ 'DstMAC' ], $limit );

        // IN batch: traffic FROM all peers TO source (grouped by SrcMAC)
        // No SrcVlan filter — each peer has a different ingress VLAN
        $inFilter = $this->buildMacFilter( 'DstMAC', $srcMacs )
            . $etypeFilter;

        $inData = $this->queryTimeSeries( $inFilter, $period, $units, [ 'SrcMAC' ], $limit );

        // Map dimension rows back to VLI IDs
        // Akvorado returns rows as numeric arrays, e.g. ["88:E0:F3:B7:0F:CF"]
        $outByVli = [];
        foreach( ( $outData['rows'] ?? [] ) as $i => $row ) {
            $mac   = strtolower( $row[0] ?? '' );
            $vliId = $macToVli[ $mac ] ?? null;

            if( $vliId === null ) {
                continue;
            }

            $outByVli[ $vliId ] = [
                't'      => $outData['t'] ?? [],
                'points' => [ $outData['points'][ $i ] ?? [] ],
            ];
        }

        $inByVli = [];
        foreach( ( $inData['rows'] ?? [] ) as $i => $row ) {
            $mac   = strtolower( $row[0] ?? '' );
            $vliId = $macToVli[ $mac ] ?? null;

            if( $vliId === null ) {
                continue;
            }

            $inByVli[ $vliId ] = [
                't'      => $inData['t'] ?? [],
                'points' => [ $inData['points'][ $i ] ?? [] ],
            ];
        }

        // Merge IN + OUT into standard grapher format per VLI
        $result = [];
        $allVliIds = array_unique( array_merge( array_keys( $outByVli ), array_keys( $inByVli ) ) );

        foreach( $allVliIds as $vliId ) {
            $in  = $inByVli[ $vliId ]  ?? [ 't' => [], 'points' => [] ];
            $out = $outByVli[ $vliId ] ?? [ 't' => [], 'points' => [] ];
            $result[ $vliId ] = $this->mergeDirectionalData( $in, $out );
        }

        return $result;
    }

    /**
     * Merge multiple grapher-format series into one by summing at each timestamp.
     *
     * Row shape (in and out): [timestamp, avg_in, avg_out, max_in, max_out].
     * `avg_*` values are summed; `max_*` values take the greater. Missing
     * timestamps in one series just fall through — no zero-padding needed.
     *
     * @param  array[]  $seriesList  Array of grapher-format series arrays.
     * @return array
     */
    private function sumSeriesByTimestamp( array $seriesList ): array
    {
        $agg = [];

        foreach( $seriesList as $series ) {
            foreach( $series as $row ) {
                $ts = $row[0] ?? null;
                if( $ts === null ) {
                    continue;
                }

                if( !isset( $agg[ $ts ] ) ) {
                    $agg[ $ts ] = [ 'avg_in' => 0.0, 'avg_out' => 0.0, 'max_in' => 0.0, 'max_out' => 0.0 ];
                }

                $agg[ $ts ][ 'avg_in'  ] += (float)( $row[1] ?? 0 );
                $agg[ $ts ][ 'avg_out' ] += (float)( $row[2] ?? 0 );
                $agg[ $ts ][ 'max_in'  ]  = max( $agg[ $ts ][ 'max_in'  ], (float)( $row[3] ?? 0 ) );
                $agg[ $ts ][ 'max_out' ]  = max( $agg[ $ts ][ 'max_out' ], (float)( $row[4] ?? 0 ) );
            }
        }

        if( empty( $agg ) ) {
            return [];
        }

        ksort( $agg );

        $result = [];
        foreach( $agg as $ts => $vals ) {
            $result[] = [ $ts, $vals['avg_in'], $vals['avg_out'], $vals['max_in'], $vals['max_out'] ];
        }

        return $result;
    }

    /**
     * Get multi-VLAN P2P traffic between two customers.
     *
     * Aggregates traffic across every VLAN both customers share. Used by the
     * MultiP2p graph type introduced in upstream v7.2.0.
     *
     * When $vlanId is provided, only that VLAN's contribution is returned —
     * this is the per-VLAN breakdown case used by /statistics/p2p-per-vlan/*.
     * When $vlanId is null, all shared VLANs are summed — the totals case
     * used by /statistics/p2p-totals/*.
     *
     * Implementation: iterates the shared VLAN set, calls p2pTraffic() per
     * (srcVli, dstVli) pair, and sums avg values / maxes max values into a
     * single time series keyed by timestamp. p2pBatchTraffic could reduce
     * API calls for very high-fan-out customers but complicates aggregation
     * across multiple source VLIs, so we keep this straightforward for now.
     *
     * Returns data in the grapher standard format:
     *   [[timestamp, avg_in, avg_out, max_in, max_out], ...]
     *
     * @param  Customer  $srcCust   Source customer
     * @param  Customer  $dstCust   Destination customer
     * @param  int|null  $vlanId    Optional VLAN filter (null = all shared VLANs)
     * @param  string    $period    Graph period
     * @param  string    $protocol  IPv4/IPv6
     * @param  string    $category  bits/packets
     *
     * @return array
     */
    public function multiP2pTraffic(
        Customer $srcCust,
        Customer $dstCust,
        ?int     $vlanId   = null,
        string   $period   = Graph::PERIOD_DAY,
        string   $protocol = Graph::PROTOCOL_IPV4,
        string   $category = Graph::CATEGORY_BITS,
    ): array {
        // Fast path: month and year views need only daily-resolution data,
        // which the nightly p2p_daily_stats aggregation already stores. Skip
        // Akvorado entirely for those periods — hundreds of concurrent Akvorado
        // queries that produce a series we can just SELECT from MySQL is silly.
        //
        // Limitations that force fallback to Akvorado:
        //   - Per-VLAN scope (setVlan) — p2p_daily_stats has no VLAN dimension.
        //   - CATEGORY_PACKETS — daily stats table only stores bytes, not pps.
        if( in_array( $period, [ Graph::PERIOD_MONTH, Graph::PERIOD_YEAR ], true )
            && $vlanId === null
            && $category === Graph::CATEGORY_BITS
        ) {
            return $this->multiP2pTrafficFromDailyStats( $srcCust, $dstCust, $period, $protocol );
        }

        $protocols = ( $protocol === Graph::PROTOCOL_ALL )
            ? [ Graph::PROTOCOL_IPV4, Graph::PROTOCOL_IPV6 ]
            : [ $protocol ];

        $sharedVlanIds = VlanInterfaceAggregator::findVlansBetweenCustomers( $srcCust, $dstCust );
        if( $vlanId !== null ) {
            $sharedVlanIds = array_intersect( $sharedVlanIds, [ $vlanId ] );
        }
        if( empty( $sharedVlanIds ) ) {
            return [];
        }

        $units = $this->categoryToUnits( $category );

        // Build every Akvorado query descriptor we need up-front so we can
        // dispatch them concurrently via Http::pool(). Each (shared VLAN,
        // src VLI, protocol) contributes 2 queries — an OUT (src→peers) and
        // an IN (peers→src). We don't need dimension grouping here since
        // multi-p2p just wants a single aggregated series — Akvorado returns
        // the total for each filter directly.
        $descriptors = [];

        foreach( $sharedVlanIds as $sharedVlanId ) {
            $srcVlis = $srcCust->vlanInterfaces()->where( 'vlaninterface.vlanid', $sharedVlanId )->get();
            $dstVlis = $dstCust->vlanInterfaces()->where( 'vlaninterface.vlanid', $sharedVlanId )->get();

            if( $srcVlis->isEmpty() || $dstVlis->isEmpty() ) {
                continue;
            }

            // Resolve destination MACs + vlan tags once per shared VLAN.
            $dstMacsAll  = [];
            $dstVlanTags = [];
            foreach( $dstVlis as $dvli ) {
                foreach( $this->resolveMACs( $dvli ) as $mac ) {
                    $dstMacsAll[ $mac ] = true;
                }
                $dstVlanTags[ $this->resolveVlan( $dvli ) ] = true;
            }
            $dstMacsAll  = array_keys( $dstMacsAll );
            $dstVlanTags = array_keys( $dstVlanTags );
            if( empty( $dstMacsAll ) ) {
                continue;
            }

            foreach( $srcVlis as $svli ) {
                $srcMacs = $this->resolveMACs( $svli );
                if( empty( $srcMacs ) ) {
                    continue;
                }
                $srcVlan = $this->resolveVlan( $svli );

                foreach( $protocols as $p ) {
                    $etypeFilter = $this->buildEtypeFilter( $p );

                    // OUT — src sending to peers on this shared VLAN
                    $descriptors[] = [
                        'key'        => "out_v{$sharedVlanId}_svli{$svli->id}_p{$p}",
                        'filter'     => $this->buildMacFilter( 'SrcMAC', $srcMacs )
                                      . ' AND ' . $this->buildMacFilter( 'DstMAC', $dstMacsAll )
                                      . " AND SrcVlan = {$srcVlan}"
                                      . $etypeFilter,
                        'period'     => $period,
                        'units'      => $units,
                        'dimensions' => [],
                        'limit'      => 5,
                    ];

                    // IN — peers sending to src on this shared VLAN.
                    // Unlike p2pBatchTraffic (which serves the network-wide
                    // p2p list and can't know each peer's ingress VLAN
                    // upfront), for multi-p2p we know exactly which VLAN
                    // tags the peers hit — so constrain SrcVlan to that set
                    // to reduce Akvorado's ClickHouse scan cost and get
                    // year-period queries under the timeout.
                    //
                    // Akvorado's filter DSL only supports boolean ops (no IN),
                    // so we OR the equalities same shape as buildMacFilter().
                    $srcVlanClauses = array_map( fn( $v ) => 'SrcVlan = ' . (int)$v, $dstVlanTags );
                    $srcVlanFilter  = count( $srcVlanClauses ) === 1
                        ? $srcVlanClauses[0]
                        : '(' . implode( ' OR ', $srcVlanClauses ) . ')';

                    $descriptors[] = [
                        'key'        => "in_v{$sharedVlanId}_svli{$svli->id}_p{$p}",
                        'filter'     => $this->buildMacFilter( 'SrcMAC', $dstMacsAll )
                                      . ' AND ' . $this->buildMacFilter( 'DstMAC', $srcMacs )
                                      . " AND {$srcVlanFilter}"
                                      . $etypeFilter,
                        'period'     => $period,
                        'units'      => $units,
                        'dimensions' => [],
                        'limit'      => 5,
                    ];
                }
            }
        }

        if( empty( $descriptors ) ) {
            return [];
        }

        // Fire everything in parallel.
        $rawResults = $this->queryTimeSeriesParallel( $descriptors );

        // Aggregate — split by direction, sum all rows per timestamp.
        $inByTs  = [];
        $outByTs = [];

        foreach( $rawResults as $key => $data ) {
            $isOut      = str_starts_with( $key, 'out_' );
            $timestamps = $this->toUnixTimestamps( $data['t'] ?? [] );
            $values     = $this->sumPoints( $data['points'] ?? [] );

            foreach( $timestamps as $i => $ts ) {
                $v = (float)( $values[ $i ] ?? 0 );
                if( $isOut ) {
                    $outByTs[ $ts ] = ( $outByTs[ $ts ] ?? 0 ) + $v;
                } else {
                    $inByTs[ $ts ]  = ( $inByTs[ $ts ]  ?? 0 ) + $v;
                }
            }
        }

        // Merge into grapher-standard rows [ts, avg_in, avg_out, max_in, max_out].
        // Akvorado gives one point per interval, so avg == max.
        $allTs = array_unique( array_merge( array_keys( $inByTs ), array_keys( $outByTs ) ) );
        sort( $allTs );

        $result = [];
        foreach( $allTs as $ts ) {
            $in  = $inByTs[ $ts ]  ?? 0;
            $out = $outByTs[ $ts ] ?? 0;
            $result[] = [ $ts, $in, $out, $in, $out ];
        }

        return $result;
    }

    /**
     * Fast-path multi-p2p from the nightly p2p_daily_stats aggregation.
     *
     * The daily job that populates this table stores per (cust_id, peer_id)
     * per-day totals (bytes) and peaks (bps) with IPv4/IPv6 broken out. For
     * month and year views daily granularity is fine, so we can serve the
     * graph directly from the DB in ~1ms instead of firing hundreds of
     * Akvorado queries.
     *
     * Row → grapher standard [ts, avg_in, avg_out, max_in, max_out]:
     *   ts       = day at 00:00 UTC (unix)
     *   avg_in   = (v4/v6-selected total_in bytes × 8) / 86400  [bps average]
     *   avg_out  = same for out
     *   max_in   = v4/v6-selected max_in [bps peak during the day]
     *   max_out  = same for out
     *
     * Called only when $vlanId is null and $category is bits. Per-VLAN and
     * packet-rate views must go through Akvorado.
     */
    private function multiP2pTrafficFromDailyStats(
        Customer $srcCust,
        Customer $dstCust,
        string   $period,
        string   $protocol,
    ): array {
        $days = ( $period === Graph::PERIOD_YEAR ) ? 365 : 30;
        $since = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        $rows = DB::table( 'p2p_daily_stats' )
            ->where( 'cust_id', $srcCust->id )
            ->where( 'peer_id', $dstCust->id )
            ->where( 'day', '>=', $since )
            ->orderBy( 'day' )
            ->get( [ 'day', 'ipv4_total_in', 'ipv4_total_out', 'ipv6_total_in', 'ipv6_total_out',
                     'ipv4_max_in', 'ipv4_max_out', 'ipv6_max_in', 'ipv6_max_out' ] );

        Log::debug( sprintf( '[Akvorado] multiP2pTrafficFromDailyStats: %d rows for cust=%d peer=%d period=%s',
            $rows->count(), $srcCust->id, $dstCust->id, $period ) );

        $wantV4 = $protocol === Graph::PROTOCOL_IPV4 || $protocol === Graph::PROTOCOL_ALL;
        $wantV6 = $protocol === Graph::PROTOCOL_IPV6 || $protocol === Graph::PROTOCOL_ALL;

        $result = [];
        foreach( $rows as $row ) {
            $ts = strtotime( $row->day . ' 00:00:00 UTC' );

            $totalIn  = 0;
            $totalOut = 0;
            $maxIn    = 0;
            $maxOut   = 0;

            if( $wantV4 ) {
                $totalIn  += (int)( $row->ipv4_total_in  ?? 0 );
                $totalOut += (int)( $row->ipv4_total_out ?? 0 );
                $maxIn    += (int)( $row->ipv4_max_in    ?? 0 );
                $maxOut   += (int)( $row->ipv4_max_out   ?? 0 );
            }
            if( $wantV6 ) {
                $totalIn  += (int)( $row->ipv6_total_in  ?? 0 );
                $totalOut += (int)( $row->ipv6_total_out ?? 0 );
                $maxIn    += (int)( $row->ipv6_max_in    ?? 0 );
                $maxOut   += (int)( $row->ipv6_max_out   ?? 0 );
            }

            // Bytes over a day → average bits per second across the day.
            $avgIn  = ( $totalIn  * 8 ) / 86400;
            $avgOut = ( $totalOut * 8 ) / 86400;

            $result[] = [ $ts, $avgIn, $avgOut, $maxIn, $maxOut ];
        }

        return $result;
    }

    /**
     * Get individual traffic for a single VlanInterface (aggregate across all peers).
     *
     * Returns data in the grapher standard format.
     */
    public function individualTraffic(
        VlanInterfaceModel $vli,
        string $period   = Graph::PERIOD_DAY,
        string $protocol = Graph::PROTOCOL_IPV4,
        string $category = Graph::CATEGORY_BITS
    ): array {
        $macs = $this->resolveMACs( $vli );

        if( empty( $macs ) ) {
            return [];
        }

        $vlan        = $this->resolveVlan( $vli );
        $units       = $this->categoryToUnits( $category );
        $etypeFilter = $this->buildEtypeFilter( $protocol );

        // IN: traffic arriving TO this VLI (DstMAC = our MAC)
        $inFilter = $this->buildMacFilter( 'DstMAC', $macs )
            . " AND DstVlan = {$vlan}"
            . $etypeFilter;

        // OUT: traffic sent FROM this VLI (SrcMAC = our MAC)
        $outFilter = $this->buildMacFilter( 'SrcMAC', $macs )
            . " AND SrcVlan = {$vlan}"
            . $etypeFilter;

        $inData  = $this->queryTimeSeries( $inFilter,  $period, $units );
        $outData = $this->queryTimeSeries( $outFilter, $period, $units );

        return $this->mergeDirectionalData( $inData, $outData );
    }

    /**
     * Get aggregate traffic for an entire VLAN/exchange.
     *
     * Uses the IXP VLAN number directly — covers all traffic on
     * that exchange regardless of per-port vlantag rewriting.
     */
    public function aggregateTraffic(
        Vlan   $vlan,
        string $period   = Graph::PERIOD_DAY,
        string $protocol = Graph::PROTOCOL_IPV4,
        string $category = Graph::CATEGORY_BITS
    ): array {
        $units       = $this->categoryToUnits( $category );
        $etypeFilter = $this->buildEtypeFilter( $protocol );

        // For aggregate: query all traffic on this VLAN
        // Use SrcVlan for outbound perspective
        $filter = "SrcVlan = {$vlan->number}" . $etypeFilter;

        $data = $this->queryTimeSeries( $filter, $period, $units );

        // Aggregate is single-direction (total throughput on the VLAN)
        // Mirror in/out as they represent the same fabric traffic
        return $this->singleDirectionToData( $data );
    }

    /**
     * Map graph category to Akvorado units.
     */
    private function categoryToUnits( string $category ): string
    {
        return match( $category ) {
            Graph::CATEGORY_PACKETS => 'pps',
            default                 => 'l3bps',
        };
    }

    /**
     * Build EType filter clause for IPv4/IPv6.
     */
    private function buildEtypeFilter( string $protocol ): string
    {
        if( isset( self::ETYPE_MAP[ $protocol ] ) ) {
            return ' AND EType = ' . self::ETYPE_MAP[ $protocol ];
        }

        return '';
    }

    /**
     * Merge two Akvorado API responses (in + out directions) into
     * the grapher standard data format.
     *
     * @param  array $inData   Akvorado response for "in" direction
     * @param  array $outData  Akvorado response for "out" direction
     *
     * @return array [[timestamp, avg_in, avg_out, max_in, max_out], ...]
     */
    private function mergeDirectionalData( array $inData, array $outData ): array
    {
        $inTimestamps  = $this->toUnixTimestamps( $inData['t']  ?? [] );
        $outTimestamps = $this->toUnixTimestamps( $outData['t'] ?? [] );

        // Sum all rows for each direction (in case multiple MACs/dimensions)
        $inValues  = $this->sumPoints( $inData['points']  ?? [] );
        $outValues = $this->sumPoints( $outData['points'] ?? [] );

        // Index by timestamp for merging
        $inByTs  = [];
        $outByTs = [];

        foreach( $inTimestamps as $i => $ts ) {
            $inByTs[ $ts ] = $inValues[ $i ] ?? 0;
        }

        foreach( $outTimestamps as $i => $ts ) {
            $outByTs[ $ts ] = $outValues[ $i ] ?? 0;
        }

        // Merge on all timestamps from both directions
        $allTimestamps = array_unique( array_merge( $inTimestamps, $outTimestamps ) );
        sort( $allTimestamps );

        $result = [];
        foreach( $allTimestamps as $ts ) {
            $in  = $inByTs[ $ts ]  ?? 0;
            $out = $outByTs[ $ts ] ?? 0;

            // avg and max are the same from Akvorado (single data point per interval)
            $result[] = [ $ts, $in, $out, $in, $out ];
        }

        return $result;
    }

    /**
     * Convert a single-direction Akvorado response to grapher data format.
     * Used for aggregate graphs where in/out are mirrored.
     */
    private function singleDirectionToData( array $data ): array
    {
        $timestamps = $this->toUnixTimestamps( $data['t'] ?? [] );
        $values     = $this->sumPoints( $data['points'] ?? [] );

        $result = [];
        foreach( $timestamps as $i => $ts ) {
            $v = $values[ $i ] ?? 0;
            $result[] = [ $ts, $v, $v, $v, $v ];
        }

        return $result;
    }

    /**
     * Convert Akvorado ISO 8601 timestamps to Unix timestamps (seconds).
     *
     * Akvorado returns timestamps as ISO 8601 strings (e.g. "2026-03-12T10:00:00Z").
     * The grapher framework expects Unix timestamps (integers).
     *
     * @param  array $timestamps Array of ISO 8601 strings or already-numeric values
     * @return array Array of integer Unix timestamps
     */
    private function toUnixTimestamps( array $timestamps ): array
    {
        return array_map( function( $ts ) {
            if( is_numeric( $ts ) ) {
                return (int) $ts;
            }
            return strtotime( $ts ) ?: 0;
        }, $timestamps );
    }

    /**
     * Sum multiple point arrays from Akvorado response.
     *
     * Akvorado returns one points array per dimension row. If we have
     * multiple MACs for a VLI, we need to sum them.
     *
     * @param  array $pointArrays Array of arrays: [[val, val, ...], [val, val, ...]]
     * @return array Single summed array: [val, val, ...]
     */
    private function sumPoints( array $pointArrays ): array
    {
        if( empty( $pointArrays ) ) {
            return [];
        }

        if( count( $pointArrays ) === 1 ) {
            return $pointArrays[0];
        }

        $length = max( array_map( 'count', $pointArrays ) );
        $result = array_fill( 0, $length, 0 );

        foreach( $pointArrays as $points ) {
            foreach( $points as $i => $val ) {
                $result[ $i ] += $val ?? 0;
            }
        }

        return $result;
    }

    /**
     * Query Akvorado for a specific date range (e.g. one calendar day).
     *
     * Unlike queryTimeSeries() which uses rolling windows from "now",
     * this method queries an explicit start/end range. No caching — intended
     * for batch/cron use.
     *
     * @param  string $filter      Akvorado filter expression
     * @param  string $start       ISO 8601 start datetime
     * @param  string $end         ISO 8601 end datetime
     * @param  string $units       Akvorado units (l3bps, pps, etc.)
     * @param  array  $dimensions  Dimensions to group by
     * @param  int    $limit       Max dimension rows
     * @param  int    $points      Number of data points
     *
     * @return array  Raw API response decoded from JSON
     */
    public function queryDateRange(
        string $filter,
        string $start,
        string $end,
        string $units      = 'l3bps',
        array  $dimensions = [],
        int    $limit      = 500,
        int    $points     = 288
    ): array {
        $payload = [
            'start'  => $start,
            'end'    => $end,
            'filter' => $filter,
            'units'  => $units,
            'points' => $points,
            'limit'  => $limit,
        ];

        if( !empty( $dimensions ) ) {
            $payload['dimensions'] = $dimensions;
        }

        Log::debug( "[Akvorado] queryDateRange: {$filter} ({$start} → {$end})" );

        try {
            $http = Http::timeout( $this->timeout );

            if( $this->authUser && $this->authPass ) {
                $http = $http->withBasicAuth( $this->authUser, $this->authPass );
            }

            $response = $http->post( "{$this->url}/api/v0/console/graph/line", $payload );

            if( !$response->successful() ) {
                Log::warning( "[Akvorado] API error: HTTP {$response->status()}", [
                    'body' => substr( $response->body(), 0, 500 ),
                ] );
                return [];
            }

            return $response->json() ?? [];
        } catch( \Exception $e ) {
            Log::warning( "[Akvorado] API request failed: {$e->getMessage()}" );
            return [];
        }
    }

    /**
     * Collect daily P2P stats for a customer on a VLAN using batch dimension queries.
     *
     * Makes 2 API calls per protocol (OUT grouped by DstMAC, IN grouped by SrcMAC)
     * instead of 2 per peer. Returns stats keyed by peer customer ID.
     *
     * @param  VlanInterfaceModel $svli      Source VlanInterface
     * @param  iterable          $dstVlis    All destination VlanInterfaces on same VLAN
     * @param  string            $day        Date in YYYY-MM-DD format
     * @param  string            $protocol   IPv4/IPv6 constant
     *
     * @return array  [ peer_cust_id => [ 'total_in' => int, 'total_out' => int, 'max_in' => int, 'max_out' => int ], ... ]
     */
    public function p2pDailyStats(
        VlanInterfaceModel $svli,
        iterable $dstVlis,
        string $day,
        string $protocol = Graph::PROTOCOL_IPV4
    ): array {
        $srcMacs = $this->resolveMACs( $svli );

        if( empty( $srcMacs ) ) {
            return [];
        }

        $srcVlan     = $this->resolveVlan( $svli );
        $etypeFilter = $this->buildEtypeFilter( $protocol );

        // Build MAC → peer customer ID mapping
        $macToCust = [];
        foreach( $dstVlis as $dvli ) {
            $custId = $dvli->virtualInterface->custid;
            foreach( $this->resolveMACs( $dvli ) as $mac ) {
                $macToCust[ $mac ] = $custId;
            }
        }

        if( empty( $macToCust ) ) {
            return [];
        }

        $limit = count( $macToCust ) + 10;
        $start = $day . 'T00:00:00Z';
        $end   = $day . 'T23:59:59Z';

        // OUT: traffic FROM source TO all peers (grouped by DstMAC)
        $outFilter = $this->buildMacFilter( 'SrcMAC', $srcMacs )
            . " AND SrcVlan = {$srcVlan}"
            . $etypeFilter;

        $outData = $this->queryDateRange( $outFilter, $start, $end, 'l3bps', [ 'DstMAC' ], $limit );

        // IN: traffic FROM all peers TO source (grouped by SrcMAC)
        $inFilter = $this->buildMacFilter( 'DstMAC', $srcMacs )
            . $etypeFilter;

        $inData = $this->queryDateRange( $inFilter, $start, $end, 'l3bps', [ 'SrcMAC' ], $limit );

        // Extract per-peer stats from response
        // Response keys: rows (numeric arrays), average (per row), max (per row)
        $result = [];

        // OUT direction → total_out and max_out per peer
        foreach( ( $outData['rows'] ?? [] ) as $i => $row ) {
            $mac    = strtolower( $row[0] ?? '' );
            $custId = $macToCust[ $mac ] ?? null;

            if( $custId === null ) {
                continue;
            }

            $avgBps = (float) ( $outData['average'][$i] ?? 0 );
            $maxBps = (float) ( $outData['max'][$i]     ?? 0 );

            // Convert average bps over 24h to total bytes: avg_bps * 86400 / 8
            $totalBytes = (int) ( $avgBps * 86400 / 8 );
            $maxRate    = (int) $maxBps;

            if( !isset( $result[ $custId ] ) ) {
                $result[ $custId ] = [ 'total_in' => 0, 'total_out' => 0, 'max_in' => 0, 'max_out' => 0 ];
            }

            $result[ $custId ]['total_out'] += $totalBytes;
            $result[ $custId ]['max_out']    = max( $result[ $custId ]['max_out'], $maxRate );
        }

        // IN direction → total_in and max_in per peer
        foreach( ( $inData['rows'] ?? [] ) as $i => $row ) {
            $mac    = strtolower( $row[0] ?? '' );
            $custId = $macToCust[ $mac ] ?? null;

            if( $custId === null ) {
                continue;
            }

            $avgBps = (float) ( $inData['average'][$i] ?? 0 );
            $maxBps = (float) ( $inData['max'][$i]     ?? 0 );

            $totalBytes = (int) ( $avgBps * 86400 / 8 );
            $maxRate    = (int) $maxBps;

            if( !isset( $result[ $custId ] ) ) {
                $result[ $custId ] = [ 'total_in' => 0, 'total_out' => 0, 'max_in' => 0, 'max_out' => 0 ];
            }

            $result[ $custId ]['total_in'] += $totalBytes;
            $result[ $custId ]['max_in']    = max( $result[ $custId ]['max_in'], $maxRate );
        }

        return $result;
    }
}
