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
 * All queries use raw OpenConfig interface counters with rate() to derive
 * per-second rates. This eliminates dependency on recording rules and
 * provides a consistent query pattern across all graph types.
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

use IXP\Models\Switcher;

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
     * Mapping of graph categories to raw OpenConfig counter metrics.
     *
     * Each category has an 'rx' and 'tx' counter name, plus a multiplier
     * (8 for octets→bits conversion, 1 for packet counters).
     */
    private const METRIC_MAP = [
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
     * Rate interval for raw counter queries.
     */
    private const RATE_INTERVAL = '30s';

    /**
     * Mapping of graph periods to PromQL time range and step size.
     */
    private const PERIOD_MAP = [
        Graph::PERIOD_DAY   => [ 'range' => '24h',  'step' => '60s'    ],
        Graph::PERIOD_WEEK  => [ 'range' => '7d',   'step' => '300s'   ],
        Graph::PERIOD_MONTH => [ 'range' => '30d',  'step' => '1800s'  ],
        Graph::PERIOD_YEAR  => [ 'range' => '365d', 'step' => '86400s' ],
    ];

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
     * Build a PromQL query for the given graph and counter metric.
     *
     * All queries use rate() on raw OpenConfig counters to derive per-second
     * rates. For aggregate graphs, rate() is wrapped in sum().
     *
     * @param Graph  $graph
     * @param string $counter     Raw OpenConfig counter (e.g. openconfig_interfaces_in_octets)
     * @param int    $multiplier  Post-rate multiplier (8 for octets→bits, 1 for pps)
     *
     * @return string|null  PromQL query string, or null if no data sources exist
     *
     * @throws CannotHandleRequestException
     */
    private function buildQueryForGraph( Graph $graph, string $counter, int $multiplier ): ?string
    {
        $interval = self::RATE_INTERVAL;
        $suffix   = $multiplier > 1 ? "*{$multiplier}" : '';

        // ── Per-port graphs: match on device_interface ──

        if( $graph instanceof PhysIntGraph ) {
            $pi    = $graph->physicalInterface();
            $label = $pi->switchPort->switcher->name . ':' . $pi->switchPort->name;

            return "rate({$counter}{device_interface=\"{$label}\"}[{$interval}]){$suffix}";
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

            return "rate({$counter}{device_interface=\"{$label}\"}[{$interval}]){$suffix}";
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

            return $this->buildSumRateQuery( $counter, $interval, $multiplier, 'device_interface', $labels );
        }

        // ── Aggregate graphs: sum rate() across matching interfaces ──

        if( $graph instanceof IXPGraph ) {
            return "sum(rate({$counter}{interface_name=~\"Ethernet.*\"}[{$interval}])){$suffix}";
        }

        if( $graph instanceof InfraGraph ) {
            $names = $graph->infrastructure()->switchers()->whereNotNull( 'name' )
                ->pluck( 'name' )->filter()->values()->all();

            if( empty( $names ) ) {
                return null;
            }

            return $this->buildSumRateQuery( $counter, $interval, $multiplier, 'device', $names, 'interface_name=~"Ethernet.*"' );
        }

        if( $graph instanceof SwitcherGraph ) {
            $switchName = $graph->switch()->name;
            return "sum(rate({$counter}{device=\"{$switchName}\",interface_name=~\"Ethernet.*\"}[{$interval}])){$suffix}";
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

            return $this->buildSumRateQuery( $counter, $interval, $multiplier, 'device', $names, 'interface_name=~"Ethernet.*"' );
        }

        // ── Core Bundle graphs: sum rate() across member ports for selected side ──

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
                return "rate({$counter}{device_interface=\"{$labels[0]}\"}[{$interval}]){$suffix}";
            }

            return $this->buildSumRateQuery( $counter, $interval, $multiplier, 'device_interface', $labels );
        }

        throw new CannotHandleRequestException( "VictoriaMetrics backend cannot handle graph type: " . $graph->classType() );
    }

    /**
     * Build a sum(rate()) PromQL query with regex alternation on a label.
     *
     * @param string      $counter      Raw OpenConfig counter name
     * @param string      $interval     Rate interval (e.g. '30s')
     * @param int         $multiplier   Post-rate multiplier
     * @param string      $label        The label to match on (e.g. 'device_interface', 'device')
     * @param array       $values       Values for regex alternation
     * @param string|null $extraFilter  Additional label filter (e.g. 'interface_name=~"Ethernet.*"')
     *
     * @return string
     */
    private function buildSumRateQuery( string $counter, string $interval, int $multiplier, string $label, array $values, ?string $extraFilter = null ): string
    {
        $suffix = $multiplier > 1 ? "*{$multiplier}" : '';

        if( count( $values ) === 1 ) {
            $filter = "{$label}=\"{$values[0]}\"";
            if( $extraFilter ) {
                $filter .= ",{$extraFilter}";
            }
            return "sum(rate({$counter}{{$filter}}[{$interval}])){$suffix}";
        }

        // Escape RE2 metacharacters for regex alternation
        $escaped = array_map(
            fn( $s ) => preg_replace( '/([.+*?^${}()\[\]\\\\|])/', '\\\\$1', $s ),
            $values
        );
        $regex  = implode( '|', $escaped );
        $filter = "{$label}=~\"{$regex}\"";

        if( $extraFilter ) {
            $filter .= ",{$extraFilter}";
        }

        return "sum(rate({$counter}{{$filter}}[{$interval}])){$suffix}";
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
     */
    #[\Override]
    public function data( Graph $graph ): array
    {
        $category  = $graph->category();
        $period    = $graph->period();

        $metrics = self::METRIC_MAP[ $category ] ?? self::METRIC_MAP[ Graph::CATEGORY_BITS ];
        $timing  = self::PERIOD_MAP[ $period ]   ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];

        $queryRx = $this->buildQueryForGraph( $graph, $metrics['rx']['counter'], $metrics['rx']['multiplier'] );
        $queryTx = $this->buildQueryForGraph( $graph, $metrics['tx']['counter'], $metrics['tx']['multiplier'] );

        // No data sources (e.g. location with no switches) — return empty
        if( $queryRx === null || $queryTx === null ) {
            return [];
        }

        $rxData = $this->queryRange( $queryRx, $timing['range'], $timing['step'] );
        $txData = $this->queryRange( $queryTx, $timing['range'], $timing['step'] );

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
            // rate() on raw counters gives us instantaneous rates at each step.
            // We use the same value for avg and max (streaming telemetry
            // at 10s resolution is already granular enough).
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
}
