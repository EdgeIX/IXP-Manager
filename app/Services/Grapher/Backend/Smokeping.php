<?php namespace IXP\Services\Grapher\Backend;

/*
 * Copyright (C) 2009-2016 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use IXP\Contracts\Grapher\Backend as GrapherBackendContract;
use IXP\Services\Grapher\Backend as GrapherBackend;

use IXP\Services\Grapher\Graph\Latency as LatencyGraph;

use IXP\Services\Grapher\Graph;

use Entities\IXP;

use IXP\Exceptions\Services\Grapher\CannotHandleRequestException;


/**
 * Grapher Backend -> Smokeping
 *
 * @author     Barry O'Donovan <barry@islandbridgenetworks.ie>
 * @category   Grapher
 * @package    IXP\Services\Grapher
 * @copyright  Copyright (C) 2009-2016 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Smokeping extends GrapherBackend implements GrapherBackendContract {

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function name(): string {
        return 'smokeping';
    }

    /**
     * The Smokeping backend requires configuration.
     *
     * {@inheritDoc}
     *
     * @return bool
     */
    public function isConfigurationRequired(): bool {
        return true;
    }

    /**
     * This function indicates whether this graphing engine supports single monolithic text
     *
     * @see \IXP\Contracts\Grapher::isMonolithicConfigurationSupported() for an explanation
     * @return bool
     */
    public function isMonolithicConfigurationSupported(): bool {
        return true;
    }

    /**
     * This function indicates whether this graphing engine supports multiple files to a directory
     *
     * @see \IXP\Contracts\Grapher::isMonolithicConfigurationSupported() for an explanation
     * @return bool
     */
    public function isMultiFileConfigurationSupported(): bool {
        return false;
    }

    /**
     * Generate the configuration file(s) for this graphing backend
     *
     * {inheritDoc}
     *
     * @param int   $type     The type of configuration to generate
     * @return array
     */
    public function generateConfiguration( int $type = self::GENERATED_CONFIG_TYPE_MONOLITHIC ): array
    {
        return [];
    }


    /**
     * Get a complete list of functionality that this backend supports.
     *
     * {inheritDoc}
     *
     * @return array
     */
    public static function supports(): array {

        return [
            'latency' => [
                'protocols'   => Graph::PROTOCOLS_REAL,
                'categories'  => Graph::CATEGORIES,
                'periods'     => LatencyGraph::PERIODS,
                'types'       => [ Graph::TYPE_PNG => Graph::TYPE_PNG ],
            ],
        ];

    }


    /**
     * Get the data points for a given graph
     *
     * {inheritDoc}
     *
     * @param \IXP\Services\Grapher\Graph $graph
     * @return array
     */
    public function data( Graph $graph ): array {
        return [];
    }

    /**
     * Get the PNG image for a given graph
     *
     * {inheritDoc}
     *
     * @param Graph $graph
     *
     * @return string
     *
     * @throws
     */
    public function png( Graph $graph ): string {
        return @file_get_contents( $this->resolveFilePath( $graph ) );
    }

    /**
     * Get the RRD file for a given graph
     *
     * {inheritDoc}
     *
     * @param Graph $graph
     *
     * @return string
     */
    public function rrd( Graph $graph ): string {
        return '';
    }


    /**
     * For a given graph, return the path where the appropriate file
     * will be found.
     *
     * @param Graph $graph
     *
     * @return string
     *
     * @throws
     */
    private function resolveFilePath( Graph $graph ): string {
        $config = config('grapher.backends.smokeping');

        switch( $graph->classType() ) {

            case 'Latency':
                /** @var LatencyGraph $graph  */
                return sprintf( "%s/?displaymode=a;start=now-%s;end=now;target=infra_%s.vlan_%s.vlanint_%s_%s", $config['url'],
                    $graph->period(), $graph->vli()->getVirtualInterface()->getPhysicalInterfaces()[0]->getSwitchPort()->getSwitcher()->getInfrastructure()->getId(),
                    $graph->vli()->getVlan()->getId(),  $graph->vli()->getId(), $graph->protocol()  );
                break;

            default:
                throw new CannotHandleRequestException("Backend asserted it could process but cannot handle graph of type: {$graph->type()}" );
        }
    }



}
