<?php

namespace IXP\Services\Grapher\Backend;

/*
 * Copyright (C) 2026 EdgeIX.
 *
 * VictoriaMetrics Grapher Backend
 *
 * Queries Victoria Metrics (PromQL-compatible) for traffic data collected
 * via gNMIC streaming telemetry from Arista EOS switches.
 *
 * Uses pre-computed recording rule metrics (gauges) rather than raw counters.
 * Recording rules apply rate() and multipliers at write time, so queries
 * are simple gauge lookups — no rate() at query time, vastly reducing
 * sample scanning and eliminating maxSamplesPerQuery errors.
 *
 * Metric names are configurable via config/grapher.php to support
 * different recording rule naming conventions across deployments.
 */

use IXP\Contracts\Grapher\Backend as GrapherBackendContract;

use IXP\Services\Grapher\{
    Backend as GrapherBackend,
    Graph
};

use IXP\Services\Grapher\Graph\{
    PhysicalInterface   as PhysIntGraph,
    VirtualInterface    as VirtIntGraph,
    Customer            as CustomerGraph,
    IXP                 as IXPGraph,
    Infrastructure      as InfraGraph,
    Switcher            as SwitcherGraph,
    Location            as LocationGraph,
    CoreBundle          as CoreBundleGraph,
};

use IXP\Models\{
    PhysicalInterface as PhysicalInterfaceModel,
    Switcher,
    SwitchPort,
};

use IXP\Exceptions\Services\Grapher\CannotHandleRequestException;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Grapher Backend -> VictoriaMetrics
 *
 * Queries VictoriaMetrics/Prometheus for port traffic data using raw
 * OpenConfig interface counters from gNMIC streaming telemetry.
 *
 * Supports physical interfaces, virtual interfaces (LAGs), customer aggregates,
 * infrastructure-level aggregates, and core bundle inter-switch links.
 */
class VictoriaMetrics extends GrapherBackend implements GrapherBackendContract
{
    /**
     * Mapping of graph periods to query time range and step size.
     *
     * Since we query pre-computed recording rule gauges, no rate interval
     * is needed — VM just returns the stored gauge value at each step.
     */
    private const PERIOD_MAP = [
        Graph::PERIOD_DAY   => [ 'range' => '24h',  'step' => '60s'    ],
        Graph::PERIOD_WEEK  => [ 'range' => '7d',   'step' => '300s'   ],
        Graph::PERIOD_MONTH => [ 'range' => '30d',  'step' => '1800s'  ],
        Graph::PERIOD_YEAR  => [ 'range' => '365d', 'step' => '86400s' ],
    ];

    /**
     * Get the recording rule metric name for a category + direction.
     *
     * Reads from config/grapher.php -> backends.victoriametrics.metrics.
     * Falls back to EdgIX defaults if not configured.
     */
    private function metricName( string $category, string $direction ): string
    {
        return config( "grapher.backends.victoriametrics.metrics.{$category}.{$direction}" )
            ?? $this->defaultMetrics()[ $category ][ $direction ];
    }

    /**
     * Default recording rule metric names (EdgIX convention).
     */
    private function defaultMetrics(): array
    {
        return [
            Graph::CATEGORY_BITS       => [ 'rx' => 'port_bitrate_rx:10s',       'tx' => 'port_bitrate_tx:10s'       ],
            Graph::CATEGORY_PACKETS    => [ 'rx' => 'port_unicast_pps_rx:10s',   'tx' => 'port_unicast_pps_tx:10s'   ],
            Graph::CATEGORY_ERRORS     => [ 'rx' => 'port_errors_pps_rx:10s',    'tx' => 'port_errors_pps_tx:10s'    ],
            Graph::CATEGORY_DISCARDS   => [ 'rx' => 'port_discards_pps_rx:10s',  'tx' => 'port_discards_pps_tx:10s'  ],
            Graph::CATEGORY_BROADCASTS => [ 'rx' => 'port_broadcasts_pps_rx:10s','tx' => 'port_broadcasts_pps_tx:10s'],
        ];
    }

    /**
     * Raw OpenConfig counter names and multipliers for fallback queries.
     *
     * Used for graph types whose ports may not be covered by recording rules
     * (e.g. core links that aren't in the ixpmanager_port enrichment metric).
     * These queries use rate() on raw counters — safe for small port sets.
     */
    private const RAW_COUNTERS = [
        Graph::CATEGORY_BITS => [
            'rx' => [ 'counter' => 'openconfig_interfaces_in_octets',          'multiplier' => 8 ],
            'tx' => [ 'counter' => 'openconfig_interfaces_out_octets',         'multiplier' => 8 ],
        ],
        Graph::CATEGORY_PACKETS => [
            'rx' => [ 'counter' => 'openconfig_interfaces_in_unicast_pkts',    'multiplier' => 1 ],
            'tx' => [ 'counter' => 'openconfig_interfaces_out_unicast_pkts',   'multiplier' => 1 ],
        ],
        Graph::CATEGORY_ERRORS => [
            'rx' => [ 'counter' => 'openconfig_interfaces_in_errors',          'multiplier' => 1 ],
            'tx' => [ 'counter' => 'openconfig_interfaces_out_errors',         'multiplier' => 1 ],
        ],
        Graph::CATEGORY_DISCARDS => [
            'rx' => [ 'counter' => 'openconfig_interfaces_in_discards',        'multiplier' => 1 ],
            'tx' => [ 'counter' => 'openconfig_interfaces_out_discards',       'multiplier' => 1 ],
        ],
        Graph::CATEGORY_BROADCASTS => [
            'rx' => [ 'counter' => 'openconfig_interfaces_in_broadcast_pkts',  'multiplier' => 1 ],
            'tx' => [ 'counter' => 'openconfig_interfaces_out_broadcast_pkts', 'multiplier' => 1 ],
        ],
    ];

    /**
     * Build a rate() query on a raw counter for a specific device_interface.
     *
     * Used as fallback for ports not covered by recording rules (core links).
     */
    private function buildRawRateQuery( string $category, string $direction, string $label, string $interval = '30s' ): string
    {
        $raw    = self::RAW_COUNTERS[ $category ][ $direction ] ?? self::RAW_COUNTERS[ Graph::CATEGORY_BITS ][ $direction ];
        $suffix = $raw['multiplier'] > 1 ? "*{$raw['multiplier']}" : '';

        return "rate({$raw['counter']}{device_interface=\"{$label}\"}[{$interval}]){$suffix}";
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function name(): string
    {
        return 'victoriametrics';
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function isConfigurationRequired(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function isMonolithicConfigurationSupported(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function isMultiFileConfigurationSupported(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function generateConfiguration( int $type = self::GENERATED_CONFIG_TYPE_MONOLITHIC, array $options = [] ): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public static function supports(): array
    {
        $base = [
            'protocols'  => [ Graph::PROTOCOL_ALL => Graph::PROTOCOL_ALL ],
            'categories' => Graph::CATEGORIES,
            'periods'    => Graph::PERIODS,
            'types'      => [
                Graph::TYPE_JSON => Graph::TYPE_JSON,
                Graph::TYPE_LOG  => Graph::TYPE_LOG,
                Graph::TYPE_PNG  => Graph::TYPE_PNG,
            ],
        ];

        return [
            'physicalinterface' => $base,
            'virtualinterface'  => $base,
            'customer'          => $base,
            'ixp'               => $base,
            'infrastructure'    => $base,
            'switcher'          => $base,
            'location'          => $base,
            'corebundle'        => $base,
        ];
    }

    /**
     * Build a PromQL query for the given graph type and recording rule metric.
     *
     * Recording rules are pre-computed gauges (already rate()*multiplier),
     * so queries are simple gauge lookups — no rate() at query time.
     *
     * @param Graph  $graph      The graph object
     * @param string $metric     Recording rule metric name (e.g. 'port_bitrate_rx:10s')
     * @param string $category   Graph category (needed for raw counter fallback on core links)
     * @param string $direction  'rx' or 'tx' (needed for raw counter fallback on core links)
     *
     * @return string|null  PromQL query string, or null if no data sources exist
     *
     * @throws CannotHandleRequestException
     */
    private function buildQueryForGraph( Graph $graph, string $metric, string $category = Graph::CATEGORY_BITS, string $direction = 'rx' ): ?string
    {
        // ── Per-port graphs: match on device_interface ──

        if( $graph instanceof PhysIntGraph ) {
            $pi    = $graph->physicalInterface();
            $label = $pi->switchPort->switcher->name . ':' . $pi->switchPort->name;

            return "{$metric}{device_interface=\"{$label}\"}";
        }

        if( $graph instanceof VirtIntGraph ) {
            $vi = $graph->virtualInterface();
            $pi = $vi->physicalInterfaces->first();

            if( !$pi || !$pi->switchPort || !$pi->switchPort->switcher ) {
                throw new CannotHandleRequestException( 'VirtualInterface has no physical interfaces with switch data' );
            }

            $switchName = $pi->switchPort->switcher->name;

            if( $vi->physicalInterfaces->count() > 1 && $vi->channelgroup ) {
                $label = "{$switchName}:Port-Channel{$vi->channelgroup}";
            } else {
                $label = "{$switchName}:{$pi->switchPort->name}";
            }

            return "{$metric}{device_interface=\"{$label}\"}";
        }

        if( $graph instanceof CustomerGraph ) {
            $labels = [];
            foreach( $graph->customer()->virtualInterfaces as $vi ) {
                $pi = $vi->physicalInterfaces->first();
                if( !$pi || !$pi->switchPort || !$pi->switchPort->switcher ) {
                    continue;
                }
                $switchName = $pi->switchPort->switcher->name;
                if( $vi->physicalInterfaces->count() > 1 && $vi->channelgroup ) {
                    $labels[] = "{$switchName}:Port-Channel{$vi->channelgroup}";
                } else {
                    $labels[] = "{$switchName}:{$pi->switchPort->name}";
                }
            }

            if( empty( $labels ) ) {
                return null;
            }

            return $this->buildSumQuery( $metric, 'device_interface', $labels );
        }

        // ── Aggregate graphs: sum across matching interfaces ──

        if( $graph instanceof IXPGraph ) {
            $names = Switcher::whereNotNull( 'name' )
                ->where( 'active', true )
                ->pluck( 'name' )
                ->filter()
                ->values()
                ->all();

            if( empty( $names ) ) {
                return null;
            }

            return $this->buildSumQuery( $metric, 'device', $names, 'interface_name=~"Ethernet.*"' );
        }

        if( $graph instanceof InfraGraph ) {
            $names = $graph->infrastructure()->switchers()->whereNotNull( 'name' )
                ->pluck( 'name' )->filter()->values()->all();

            if( empty( $names ) ) {
                return null;
            }

            return $this->buildSumQuery( $metric, 'device', $names, 'interface_name=~"Ethernet.*"' );
        }

        if( $graph instanceof SwitcherGraph ) {
            $switchName = $graph->switch()->name;
            return "sum({$metric}{device=\"{$switchName}\",interface_name=~\"Ethernet.*\"})";
        }

        if( $graph instanceof LocationGraph ) {
            $cabinetIds = $graph->location()->cabinets()->pluck( 'id' )->all();

            $names = Switcher::whereIn( 'cabinetid', $cabinetIds )
                ->whereNotNull( 'name' )
                ->pluck( 'name' )
                ->filter()
                ->values()
                ->all();

            if( empty( $names ) ) {
                return null;
            }

            return $this->buildSumQuery( $metric, 'device', $names, 'interface_name=~"Ethernet.*"' );
        }

        // ── Core Bundle graphs: sum across member ports for selected side ──
        // Core link ports are typically not in recording rules (no customer
        // in ixpmanager_port), so we fall back to raw counter queries with
        // rate(). This is safe — core bundles have only a few ports.

        if( $graph instanceof CoreBundleGraph ) {
            $cb   = $graph->coreBundle();
            $side = $graph->side();

            $labels = [];
            foreach( $cb->corelinks as $cl ) {
                $ci = $side === 'a' ? $cl->coreInterfaceSideA : $cl->coreInterfaceSideB;
                $pi = $ci->physicalInterface;

                if( !$pi || !$pi->switchPort || !$pi->switchPort->switcher ) {
                    continue;
                }

                $labels[] = $pi->switchPort->switcher->name . ':' . $pi->switchPort->name;
            }

            if( empty( $labels ) ) {
                return null;
            }

            if( count( $labels ) === 1 ) {
                return $this->buildRawRateQuery( $category, $direction, $labels[0] );
            }

            // Multiple core link members — sum raw rate() queries
            $raw    = self::RAW_COUNTERS[ $category ][ $direction ] ?? self::RAW_COUNTERS[ Graph::CATEGORY_BITS ][ $direction ];
            $suffix = $raw['multiplier'] > 1 ? "*{$raw['multiplier']}" : '';

            $escaped = array_map(
                fn( $s ) => preg_replace( '/([.+*?^${}()\[\]\\\\|])/', '\\\\\\\\$1', $s ),
                $labels
            );
            $regex = implode( '|', $escaped );

            return "sum(rate({$raw['counter']}{device_interface=~\"{$regex}\"}[30s])){$suffix}";
        }

        throw new CannotHandleRequestException( "VictoriaMetrics backend cannot handle graph type: " . $graph->classType() );
    }

    /**
     * Build a sum() PromQL query with regex alternation on a label.
     *
     * For recording rule gauges — no rate() needed.
     *
     * @param string      $metric      Recording rule metric name
     * @param string      $label       The label to match on (e.g. 'device_interface', 'device')
     * @param array       $values      Values for regex alternation
     * @param string|null $extraFilter Additional label filter (e.g. 'interface_name=~"Ethernet.*"')
     *
     * @return string
     */
    private function buildSumQuery( string $metric, string $label, array $values, ?string $extraFilter = null ): string
    {
        if( count( $values ) === 1 ) {
            $filter = "{$label}=\"{$values[0]}\"";
            if( $extraFilter ) {
                $filter .= ",{$extraFilter}";
            }
            return "sum({$metric}{{$filter}})";
        }

        // Escape RE2 metacharacters for regex alternation.
        // MetricsQL string literals use Go-style escaping, so a regex
        // backslash must be written as \\ inside the "..." string.
        $escaped = array_map(
            fn( $s ) => preg_replace( '/([.+*?^${}()\[\]\\\\|])/', '\\\\\\\\$1', $s ),
            $values
        );
        $regex  = implode( '|', $escaped );
        $filter = "{$label}=~\"{$regex}\"";

        if( $extraFilter ) {
            $filter .= ",{$extraFilter}";
        }

        return "sum({$metric}{{$filter}})";
    }

    /**
     * Query Victoria Metrics range API.
     *
     * @param string $query  PromQL query
     * @param string $range  Time range (e.g. '24h')
     * @param string $step   Step interval (e.g. '60s')
     *
     * @return array  Array of [timestamp, value] pairs
     */
    private function queryRange( string $query, string $range, string $step ): array
    {
        $baseUrl = config( 'grapher.backends.victoriametrics.url', 'http://localhost:8428' );
        $url     = rtrim( $baseUrl, '/' ) . '/api/v1/query_range';

        $end   = time();
        $start = $end - $this->rangeToSeconds( $range );

        Log::debug( "[Grapher] [VictoriaMetrics] Querying: {$url}", [
            'query' => $query,
            'start' => $start,
            'end'   => $end,
            'step'  => $step,
        ]);

        try {
            $response = Http::timeout( 30 )->get( $url, [
                'query' => $query,
                'start' => $start,
                'end'   => $end,
                'step'  => $step,
            ]);

            Log::debug( "[Grapher] [VictoriaMetrics] Response: HTTP {$response->status()}", [
                'body' => substr( $response->body(), 0, 500 ),
            ]);

            if( !$response->successful() ) {
                Log::warning( "[Grapher] [VictoriaMetrics] Query failed: HTTP {$response->status()} for query: {$query}" );
                return [];
            }

            $data = $response->json();

            if( ( $data['status'] ?? '' ) !== 'success' ) {
                Log::warning( "[Grapher] [VictoriaMetrics] Query returned non-success status for: {$query}" );
                return [];
            }

            $results = $data['data']['result'] ?? [];

            if( empty( $results ) ) {
                return [];
            }

            // For sum() queries there will be one result; for single series also one.
            // Take the first result's values.
            return $results[0]['values'] ?? [];

        } catch( \Exception $e ) {
            Log::error( "[Grapher] [VictoriaMetrics] Query exception: {$e->getMessage()} for query: {$query}" );
            return [];
        }
    }

    /**
     * Convert a range string like '24h', '7d', '365d' to seconds.
     *
     * @param string $range
     * @return int
     */
    private function rangeToSeconds( string $range ): int
    {
        if( preg_match( '/^(\d+)([hdwmy])$/', $range, $m ) ) {
            $n = (int) $m[1];
            return match( $m[2] ) {
                'h' => $n * 3600,
                'd' => $n * 86400,
                'w' => $n * 604800,
                'm' => $n * 2592000,
                'y' => $n * 31536000,
                default => $n,
            };
        }
        return 86400; // fallback: 1 day
    }

    /**
     * {@inheritDoc}
     *
     * Returns array of [timestamp, avg_in, avg_out, max_in, max_out]
     * ordered oldest first.
     *
     * Queries pre-computed recording rule gauges — no rate() at query time.
     */
    #[\Override]
    public function data( Graph $graph ): array
    {
        $category = $graph->category();
        $period   = $graph->period();
        $timing   = self::PERIOD_MAP[ $period ] ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];

        $rxMetric = $this->metricName( $category, 'rx' );
        $txMetric = $this->metricName( $category, 'tx' );

        $queryRx = $this->buildQueryForGraph( $graph, $rxMetric, $category, 'rx' );
        $queryTx = $this->buildQueryForGraph( $graph, $txMetric, $category, 'tx' );

        // No data sources (e.g. location with no switches) — return empty
        if( $queryRx === null || $queryTx === null ) {
            return [];
        }

        $rxData = $this->queryRange( $queryRx, $timing['range'], $timing['step'] );
        $txData = $this->queryRange( $queryTx, $timing['range'], $timing['step'] );

        // If recording rule returned no data for a single-port graph, retry
        // with raw counters. This handles core link ports and other interfaces
        // that aren't covered by enriched recording rules.
        if( empty( $rxData ) && empty( $txData ) && $graph instanceof PhysIntGraph ) {
            $pi    = $graph->physicalInterface();
            $label = $pi->switchPort->switcher->name . ':' . $pi->switchPort->name;

            $rawRx = $this->buildRawRateQuery( $category, 'rx', $label );
            $rawTx = $this->buildRawRateQuery( $category, 'tx', $label );

            $rxData = $this->queryRange( $rawRx, $timing['range'], $timing['step'] );
            $txData = $this->queryRange( $rawTx, $timing['range'], $timing['step'] );
        }

        // Index TX data by timestamp for merging
        $txByTimestamp = [];
        foreach( $txData as $point ) {
            $ts = (int) $point[0];
            $txByTimestamp[ $ts ] = (float) $point[1];
        }

        // Merge RX and TX into the required format
        $data = [];
        foreach( $rxData as $point ) {
            $ts    = (int) $point[0];
            $rxVal = (float) $point[1];
            $txVal = $txByTimestamp[ $ts ] ?? 0.0;

            // [timestamp, avg_in, avg_out, max_in, max_out]
            // Recording rules give us pre-computed rates at 10s resolution.
            // We use the same value for avg and max.
            $data[] = [ $ts, $rxVal, $txVal, $rxVal, $txVal ];
        }

        // Ensure oldest first
        usort( $data, fn( $a, $b ) => $a[0] <=> $b[0] );

        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * We don't generate PNGs server-side. The uPlot renderer handles
     * client-side rendering. Return a 1x1 transparent PNG as fallback
     * for any code path that still calls png().
     */
    #[\Override]
    public function png( Graph $graph ): false|string
    {
        // 1x1 transparent PNG
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function rrd( Graph $graph ): false|string
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function dataPath( Graph $graph ): string
    {
        return '';
    }

    // ════════════════════════════════════════════════════════════════════
    // DOM (Digital Optical Monitoring) data
    // ════════════════════════════════════════════════════════════════════

    /**
     * DOM metric names. These use device + interface_name labels
     * (not the combined device_interface used by traffic metrics).
     */
    private const DOM_METRICS = [
        'rx' => 'dom_rx_power:interface',
        'tx' => 'dom_tx_power:interface',
    ];

    /**
     * Fetch DOM (optical power) time series for a physical interface.
     *
     * Returns per-channel RX/TX optical power in dBm.
     *
     * @param PhysicalInterfaceModel $pi
     * @param string                 $period  Graph period constant
     *
     * @return array  [ 'channels' => [ [ 'channel_index' => '0', 'rx' => [[ts,val],...], 'tx' => [[ts,val],...] ], ... ] ]
     */
    public function domData( PhysicalInterfaceModel $pi, string $period = Graph::PERIOD_DAY ): array
    {
        $device        = $pi->switchPort->switcher->name;
        $interfaceName = $pi->switchPort->name;
        $timing        = self::PERIOD_MAP[ $period ] ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];

        $rxQuery = self::DOM_METRICS['rx'] . "{device=\"{$device}\",interface_name=\"{$interfaceName}\"}";
        $txQuery = self::DOM_METRICS['tx'] . "{device=\"{$device}\",interface_name=\"{$interfaceName}\"}";

        $rxResults = $this->queryRangeMulti( $rxQuery, $timing['range'], $timing['step'] );
        $txResults = $this->queryRangeMulti( $txQuery, $timing['range'], $timing['step'] );

        // Index TX results by channel_index for merging
        $txByChannel = [];
        foreach( $txResults as $series ) {
            $ch = $series['metric']['channel_index'] ?? '0';
            $txByChannel[ $ch ] = $series['values'] ?? [];
        }

        $channels = [];
        foreach( $rxResults as $series ) {
            $ch = $series['metric']['channel_index'] ?? '0';
            $channels[] = [
                'channel_index' => $ch,
                'rx'            => $series['values'] ?? [],
                'tx'            => $txByChannel[ $ch ] ?? [],
            ];
        }

        // If we got TX channels but no RX (unusual), include them
        foreach( $txByChannel as $ch => $values ) {
            $found = false;
            foreach( $channels as $c ) {
                if( $c['channel_index'] === $ch ) { $found = true; break; }
            }
            if( !$found ) {
                $channels[] = [
                    'channel_index' => $ch,
                    'rx'            => [],
                    'tx'            => $values,
                ];
            }
        }

        // Sort by channel index
        usort( $channels, fn( $a, $b ) => (int) $a['channel_index'] <=> (int) $b['channel_index'] );

        return [ 'channels' => $channels ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Interface status (oper state) data
    // ════════════════════════════════════════════════════════════════════

    /**
     * Fetch interface operational status time series.
     *
     * @param PhysicalInterfaceModel $pi
     * @param string                 $period
     *
     * @return array  Array of [timestamp, status] pairs (1=UP)
     */
    public function statusData( PhysicalInterfaceModel $pi, string $period = Graph::PERIOD_DAY ): array
    {
        $label  = $pi->switchPort->switcher->name . ':' . $pi->switchPort->name;
        $timing = self::PERIOD_MAP[ $period ] ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];

        $query = "openconfig_interfaces_oper_status{device_interface=\"{$label}\"}";

        return $this->queryRange( $query, $timing['range'], $timing['step'] );
    }

    // ════════════════════════════════════════════════════════════════════
    // Top-N interfaces by traffic
    // ════════════════════════════════════════════════════════════════════

    /**
     * Get the top N interfaces by current traffic rate.
     *
     * @param int    $limit      Number of results
     * @param string $direction  'in' or 'out'
     *
     * @return array  [ [ 'device_interface' => 'pe1syd3:Ethernet9/2', 'rate_bps' => 45000000000.0 ], ... ]
     */
    public function topInterfaces( int $limit = 20, string $direction = 'in' ): array
    {
        $metric = $this->metricName( Graph::CATEGORY_BITS, $direction === 'out' ? 'tx' : 'rx' );

        $query = "topk({$limit}, {$metric}{interface_name=~\"Ethernet.*\"})";

        $results = $this->queryInstant( $query );

        $top = [];
        foreach( $results as $series ) {
            $deviceInterface = $series['metric']['device_interface'] ?? null;
            $value           = (float) ( $series['value'][1] ?? 0 );

            if( $deviceInterface ) {
                $top[] = [
                    'device_interface' => $deviceInterface,
                    'rate_bps'         => $value,
                ];
            }
        }

        // Sort descending by rate (topk should already, but be safe)
        usort( $top, fn( $a, $b ) => $b['rate_bps'] <=> $a['rate_bps'] );

        return $top;
    }

    /**
     * Resolve a device_interface label to its database models.
     *
     * @param string $deviceInterface  e.g. "pe1syd3:Ethernet9/2"
     *
     * @return array|null  [ 'switcher' => Switcher, 'switchPort' => SwitchPort, 'pi' => PhysicalInterface, 'customer' => Customer ] or null
     */
    public function resolveDeviceInterface( string $deviceInterface ): ?array
    {
        $parts = explode( ':', $deviceInterface, 2 );
        if( count( $parts ) !== 2 ) {
            return null;
        }

        [ $switchName, $portName ] = $parts;

        $switcher = Switcher::where( 'name', $switchName )->first();
        if( !$switcher ) {
            return null;
        }

        $switchPort = SwitchPort::where( 'switchid', $switcher->id )
            ->where( 'name', $portName )
            ->first();

        if( !$switchPort ) {
            return null;
        }

        $pi = $switchPort->physicalInterface;
        if( !$pi ) {
            return null;
        }

        $vi = $pi->virtualInterface;
        $customer = $vi ? $vi->customer : null;

        return [
            'switcher'   => $switcher,
            'switchPort'  => $switchPort,
            'pi'          => $pi,
            'vi'          => $vi,
            'customer'    => $customer,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Query helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Query Victoria Metrics range API, returning ALL result series.
     *
     * Unlike queryRange() which returns only the first result's values,
     * this returns all series with their metric labels — needed for
     * multi-channel DOM data and multi-series queries.
     *
     * @param string $query  PromQL query
     * @param string $range  Time range (e.g. '24h')
     * @param string $step   Step interval (e.g. '60s')
     *
     * @return array  Array of [ 'metric' => [...], 'values' => [[ts, val], ...] ]
     */
    private function queryRangeMulti( string $query, string $range, string $step ): array
    {
        $baseUrl = config( 'grapher.backends.victoriametrics.url', 'http://localhost:8428' );
        $url     = rtrim( $baseUrl, '/' ) . '/api/v1/query_range';

        $end   = time();
        $start = $end - $this->rangeToSeconds( $range );

        Log::debug( "[Grapher] [VictoriaMetrics] QueryRangeMulti: {$url}", [
            'query' => $query,
            'start' => $start,
            'end'   => $end,
            'step'  => $step,
        ]);

        try {
            $response = Http::timeout( 30 )->get( $url, [
                'query' => $query,
                'start' => $start,
                'end'   => $end,
                'step'  => $step,
            ]);

            if( !$response->successful() ) {
                Log::warning( "[Grapher] [VictoriaMetrics] QueryRangeMulti failed: HTTP {$response->status()}" );
                return [];
            }

            $data = $response->json();

            if( ( $data['status'] ?? '' ) !== 'success' ) {
                return [];
            }

            return $data['data']['result'] ?? [];

        } catch( \Exception $e ) {
            Log::error( "[Grapher] [VictoriaMetrics] QueryRangeMulti exception: {$e->getMessage()}" );
            return [];
        }
    }

    /**
     * Query Victoria Metrics instant API (current values).
     *
     * @param string $query  PromQL query
     *
     * @return array  Array of [ 'metric' => [...], 'value' => [ts, val] ]
     */
    private function queryInstant( string $query ): array
    {
        $baseUrl = config( 'grapher.backends.victoriametrics.url', 'http://localhost:8428' );
        $url     = rtrim( $baseUrl, '/' ) . '/api/v1/query';

        Log::debug( "[Grapher] [VictoriaMetrics] QueryInstant: {$url}", [
            'query' => $query,
        ]);

        try {
            $response = Http::timeout( 30 )->get( $url, [
                'query' => $query,
                'time'  => time(),
            ]);

            if( !$response->successful() ) {
                Log::warning( "[Grapher] [VictoriaMetrics] QueryInstant failed: HTTP {$response->status()}" );
                return [];
            }

            $data = $response->json();

            if( ( $data['status'] ?? '' ) !== 'success' ) {
                return [];
            }

            return $data['data']['result'] ?? [];

        } catch( \Exception $e ) {
            Log::error( "[Grapher] [VictoriaMetrics] QueryInstant exception: {$e->getMessage()}" );
            return [];
        }
    }
}
