<?php

namespace IXP\Services\Grapher\Backend;

/*
 * Copyright (C) 2026 EdgeIX.
 *
 * VictoriaMetrics Grapher Backend
 *
 * Queries Victoria Metrics (PromQL-compatible) for traffic data collected
 * via gNMIC streaming telemetry from Arista EOS switches.
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
};

use IXP\Exceptions\Services\Grapher\CannotHandleRequestException;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Grapher Backend -> VictoriaMetrics
 *
 * Queries VictoriaMetrics/Prometheus for port traffic data.
 * Supports physical interfaces, virtual interfaces (LAGs), and customer aggregates.
 */
class VictoriaMetrics extends GrapherBackend implements GrapherBackendContract
{
    /**
     * Mapping of graph categories to VM recording rule metric prefixes.
     *
     * Each category has an 'rx' and 'tx' metric name.
     */
    private const METRIC_MAP = [
        Graph::CATEGORY_BITS => [
            'rx' => 'port_bitrate_rx:10s',
            'tx' => 'port_bitrate_tx:10s',
        ],
        Graph::CATEGORY_PACKETS => [
            'rx' => 'port_unicast_pps_rx:10s',
            'tx' => 'port_unicast_pps_tx:10s',
        ],
        Graph::CATEGORY_ERRORS => [
            'rx' => 'port_errors_pps_rx:10s',
            'tx' => 'port_errors_pps_tx:10s',
        ],
        Graph::CATEGORY_DISCARDS => [
            'rx' => 'port_discards_pps_rx:10s',
            'tx' => 'port_discards_pps_tx:10s',
        ],
        Graph::CATEGORY_BROADCASTS => [
            'rx' => 'port_broadcast_pps_rx:10s',
            'tx' => 'port_broadcast_pps_tx:10s',
        ],
    ];

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
        ];
    }

    /**
     * Build the device_interface label value for a graph.
     *
     * @param Graph $graph
     * @return string|array  A single label string, or array of strings for customer aggregates
     *
     * @throws CannotHandleRequestException
     */
    private function resolveDeviceInterface( Graph $graph ): string|array
    {
        if( $graph instanceof PhysIntGraph ) {
            $pi = $graph->physicalInterface();
            $switchName = $pi->switchPort->switcher->name;
            $portName   = $pi->switchPort->name;
            return "{$switchName}:{$portName}";
        }

        if( $graph instanceof VirtIntGraph ) {
            $vi = $graph->virtualInterface();
            $pi = $vi->physicalInterfaces->first();

            if( !$pi || !$pi->switchPort || !$pi->switchPort->switcher ) {
                throw new CannotHandleRequestException( 'VirtualInterface has no physical interfaces with switch data' );
            }

            $switchName = $pi->switchPort->switcher->name;

            // LAG (multiple physical interfaces) — use Port-Channel
            if( $vi->physicalInterfaces->count() > 1 && $vi->channelgroup ) {
                return "{$switchName}:Port-Channel{$vi->channelgroup}";
            }

            // Single physical interface — use port name directly
            return "{$switchName}:{$pi->switchPort->name}";
        }

        if( $graph instanceof CustomerGraph ) {
            // Customer aggregate — return array of all port device_interface labels
            $labels = [];
            $customer = $graph->customer();

            foreach( $customer->virtualInterfaces as $vi ) {
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
                throw new CannotHandleRequestException( 'Customer has no graphable interfaces' );
            }

            return $labels;
        }

        throw new CannotHandleRequestException( "VictoriaMetrics backend cannot handle graph type: " . $graph->classType() );
    }

    /**
     * Build PromQL query for a given metric and device_interface selector.
     *
     * @param string       $metric   The metric name (e.g. port_bitrate_rx:10s)
     * @param string|array $selector A device_interface label or array of labels
     *
     * @return string
     */
    private function buildQuery( string $metric, string|array $selector ): string
    {
        if( is_array( $selector ) ) {
            // Multiple interfaces — use regex alternation with sum()
            // Only escape RE2 metacharacters (not colons/slashes which are literal in PromQL strings)
            $escaped = array_map( fn( $s ) => preg_replace( '/([.+*?^${}()\[\]\\\\|])/', '\\\\$1', $s ), $selector );
            $regex   = implode( '|', $escaped );
            return "sum({$metric}{device_interface=~\"{$regex}\"})";
        }

        return "{$metric}{device_interface=\"{$selector}\"}";
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
        $selector  = $this->resolveDeviceInterface( $graph );

        $metrics = self::METRIC_MAP[ $category ] ?? self::METRIC_MAP[ Graph::CATEGORY_BITS ];
        $timing  = self::PERIOD_MAP[ $period ]   ?? self::PERIOD_MAP[ Graph::PERIOD_DAY ];

        $queryRx = $this->buildQuery( $metrics['rx'], $selector );
        $queryTx = $this->buildQuery( $metrics['tx'], $selector );

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
            // VM recording rules give us rates, not separate avg/max.
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
