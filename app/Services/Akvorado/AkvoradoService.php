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

use Illuminate\Support\Facades\{Cache, Http};

use IXP\Models\{
    Vlan,
    VlanInterface as VlanInterfaceModel
};

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

        $limit = max( $numPeers + 5, 10 );

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

        // Debug: log the response structure to identify MAC format
        Log::debug( "[Akvorado] p2pBatch macToVli mapping", [
            'macToVli_keys' => array_keys( $macToVli ),
            'outData_rows'  => array_slice( $outData['rows'] ?? [], 0, 5 ),
            'inData_rows'   => array_slice( $inData['rows'] ?? [], 0, 5 ),
            'outData_keys'  => array_keys( $outData ),
            'inData_keys'   => array_keys( $inData ),
        ] );

        // Map dimension rows back to VLI IDs
        $outByVli = [];
        foreach( ( $outData['rows'] ?? [] ) as $i => $row ) {
            $mac   = strtolower( $row['DstMAC'] ?? '' );
            $vliId = $macToVli[ $mac ] ?? null;

            Log::debug( "[Akvorado] OUT row {$i}: row=" . json_encode( $row ) . " mac={$mac} vliId=" . ( $vliId ?? 'null' ) );

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
            $mac   = strtolower( $row['SrcMAC'] ?? '' );
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
}
