<?php

namespace IXP\Services\Grapher\Backend;

/*
 * Copyright (C) 2026 EdgeIX Pty Ltd.
 *
 * Akvorado grapher backend for IXP Manager.
 *
 * Replaces the legacy Sflow/RRD backend with Akvorado REST API queries.
 * Uses MAC addresses + VLAN tags to identify peer-to-peer traffic flows.
 *
 * Handles graph types: P2P, VlanInterface (individual), Vlan (aggregate).
 */

use Log;

use IXP\Contracts\Grapher\Backend as GrapherBackendContract;

use IXP\Exceptions\Services\Grapher\CannotHandleRequestException;

use IXP\Services\Akvorado\AkvoradoService;
use IXP\Services\Grapher\Backend as GrapherBackend;
use IXP\Services\Grapher\Graph;

class Akvorado extends GrapherBackend implements GrapherBackendContract
{
    /**
     * @var AkvoradoService|null
     */
    private ?AkvoradoService $service = null;

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function name(): string
    {
        return 'akvorado';
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
     * Get a complete list of functionality that this backend supports.
     *
     * Supports the same graph types as Sflow (P2P, VlanInterface, Vlan)
     * with IPv4/IPv6 protocol filtering and bits/packets categories.
     *
     * {@inheritDoc}
     */
    #[\Override]
    public static function supports(): array
    {
        $graphProtocols = Graph::PROTOCOLS;
        unset( $graphProtocols[ Graph::PROTOCOL_ALL ] );

        return [
            'vlan' => [
                'protocols'  => Graph::PROTOCOLS_REAL,
                'categories' => [
                    Graph::CATEGORY_BITS    => Graph::CATEGORY_BITS,
                    Graph::CATEGORY_PACKETS => Graph::CATEGORY_PACKETS,
                ],
                'periods'    => Graph::PERIODS,
                'types'      => Graph::TYPES,
            ],
            'vlaninterface' => [
                'protocols'  => $graphProtocols,
                'categories' => [
                    Graph::CATEGORY_BITS    => Graph::CATEGORY_BITS,
                    Graph::CATEGORY_PACKETS => Graph::CATEGORY_PACKETS,
                ],
                'periods'    => Graph::PERIODS,
                'types'      => Graph::TYPES,
            ],
            'p2p' => [
                'protocols'  => $graphProtocols,
                'categories' => [
                    Graph::CATEGORY_BITS    => Graph::CATEGORY_BITS,
                    Graph::CATEGORY_PACKETS => Graph::CATEGORY_PACKETS,
                ],
                'periods'    => Graph::PERIODS_EXTENDED,
                'types'      => Graph::TYPES,
            ],
        ];
    }

    /**
     * Get the data points for a given graph.
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function data( Graph $graph ): array
    {
        $service = $this->service();

        switch( $graph->classType() ) {
            case 'P2p':
                /** @var Graph\P2p $graph */
                return $service->p2pTraffic(
                    $graph->svli(), $graph->dvli(),
                    $graph->period(), $graph->protocol(), $graph->category()
                );

            case 'VlanInterface':
                /** @var Graph\VlanInterface $graph */
                return $service->individualTraffic(
                    $graph->vlanInterface(),
                    $graph->period(), $graph->protocol(), $graph->category()
                );

            case 'Vlan':
                /** @var Graph\Vlan $graph */
                return $service->aggregateTraffic(
                    $graph->vlan(),
                    $graph->period(), $graph->protocol(), $graph->category()
                );

            default:
                throw new CannotHandleRequestException(
                    "Backend asserted it could process but cannot handle graph of type: {$graph->classType()}"
                );
        }
    }

    /**
     * Get the PNG image for a given graph.
     *
     * Akvorado renders via uPlot in the browser — no server-side PNG.
     * Return a 1x1 transparent PNG placeholder.
     *
     * {@inheritDoc}
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
     * No local data files — Akvorado is a remote API.
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function dataPath( Graph $graph ): string
    {
        return '';
    }

    /**
     * No RRD files — Akvorado uses ClickHouse.
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function rrd( Graph $graph ): false|string
    {
        return false;
    }

    /**
     * Get or create the AkvoradoService instance.
     */
    private function service(): AkvoradoService
    {
        if( $this->service === null ) {
            $this->service = new AkvoradoService();
        }

        return $this->service;
    }
}
