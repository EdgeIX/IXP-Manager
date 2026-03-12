<?php

namespace IXP\Services\LookingGlass;

/*
 * Copyright (C) 2009 - 2021 Internet Neutral Exchange Association Company Limited By Guarantee.
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

use IXP\Contracts\LookingGlass as LookingGlassContract;

use IXP\Models\Router;

/**
 * LookingGlass Backend -> Birdwatcher (alice-lg/birdwatcher)
 *
 * Birdwatcher is a Go-based HTTP/JSON API for the BIRD routing daemon.
 * Key differences from Birdseye:
 *   - No /api prefix (endpoints at root)
 *   - Cache bypass via ?uncached=true (not ?use_cache=0)
 *   - No single-protocol endpoint; use /protocols/bgp and filter client-side
 *   - Additional endpoints: /routes/filtered, /routes/noexport, /routes/peer
 */
class BirdWatcher implements LookingGlassContract
{
    /**
     * Instance of a router object representing the looking glass target
     *
     * @var Router
     */
    private $router;

    /**
     * Is caching enabled?
     *
     * @var bool
     */
    private $cacheEnabled = true;

    /**
     * Constructor
     * @param Router $r
     */
    public function __construct( Router $r )
    {
        $this->setRouter( $r );
    }

    /**
     * Enable / disable caching
     *
     * @param bool $b
     *
     * @return static
     */
    public function setCacheEnabled( bool $b ): static
    {
        $this->cacheEnabled = $b;
        return $this;
    }

    /**
     * Is caching enabled?
     *
     * @return bool
     */
    public function cacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Set the router object
     *
     * @param Router $r
     *
     * @return LookingGlassContract For fluent interfaces
     */
    #[\Override]
    public function setRouter( Router $r ): LookingGlassContract
    {
        $this->router = $r;
        return $this;
    }

    /**
     * Get the router object
     *
     * @return Router
     */
    #[\Override]
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Make the API call to Birdwatcher
     *
     * Birdwatcher uses ?uncached=true to bypass cache (unlike Birdseye's ?use_cache=0)
     *
     * @param string $cmd
     *
     * @return string
     */
    private function apiCall( string $cmd ): string
    {
        $url = rtrim( $this->router()->api, '/' ) . '/' . $cmd;

        if( !$this->cacheEnabled ) {
            $url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . 'uncached=true';
        }

        $ctx = stream_context_create( [ 'http' => [ 'timeout' => 15, 'ignore_errors' => true ] ] );
        $ret = @file_get_contents( $url, false, $ctx );

        // Check for empty response, HTTP errors, or non-JSON responses
        if( !$ret || !str_starts_with( trim( $ret ), '{' ) ) {
            // Return valid JSON so controllers using JSON_THROW_ON_ERROR don't 500
            return json_encode( [
                'api'       => [ 'version' => 'birdwatcher', 'from_cache' => false, 'max_routes' => 0 ],
                'status'    => [ 'version' => 'unavailable', 'message' => 'Birdwatcher API unreachable', 'router_id' => '',
                                 'last_reboot' => '1970-01-01T00:00:00+0000', 'last_reconfig' => '1970-01-01T00:00:00+0000' ],
                'protocols' => (object)[],
                'routes'    => [],
            ] );
        }

        return $this->normalizeResponse( $ret );
    }

    /**
     * Normalize Birdwatcher API response to match Birdseye format expected by templates.
     *
     * Key differences:
     *   - api.Version (capital V) → api.version
     *   - api.result_from_cache → api.from_cache
     *   - Dates: "Y-m-d H:i:s" → ISO 8601 "Y-m-d\TH:i:sO"
     *   - ttl as timestamp → api.ttl_mins
     *
     * @param string $response Raw JSON from Birdwatcher
     * @return string Normalized JSON
     */
    private function normalizeResponse( string $response ): string
    {
        $data = json_decode( $response, true );

        if( !$data ) {
            return $response;
        }

        // Normalize api section
        if( isset( $data['api'] ) ) {
            // Version → version
            if( isset( $data['api']['Version'] ) && !isset( $data['api']['version'] ) ) {
                $data['api']['version'] = $data['api']['Version'];
                unset( $data['api']['Version'] );
            }

            // result_from_cache → from_cache
            if( isset( $data['api']['result_from_cache'] ) ) {
                $data['api']['from_cache'] = $data['api']['result_from_cache'];
            }

            // Calculate ttl_mins from ttl and cached_at timestamps
            if( isset( $data['ttl'] ) && isset( $data['cached_at'] ) ) {
                try {
                    $ttl = new \DateTime( $data['ttl'] );
                    $cached = new \DateTime( $data['cached_at'] );
                    $data['api']['ttl_mins'] = max( 1, (int)round( ( $ttl->getTimestamp() - $cached->getTimestamp() ) / 60 ) );
                } catch( \Exception $e ) {
                    $data['api']['ttl_mins'] = 5;
                }
            }

            // Ensure max_routes exists (used by bgp-summary template for link display).
            // Configurable via IXP_API_LOOKING_GLASS_BIRDWATCHER_MAX_ROUTES in .env
            if( !isset( $data['api']['max_routes'] ) ) {
                $data['api']['max_routes'] = (int)config( 'ixp_api.looking_glass.birdwatcher_max_routes', 1000000 );
            }
        }

        // Normalize status section date formats: "Y-m-d H:i:s" → ISO 8601
        if( isset( $data['status'] ) ) {
            foreach( [ 'last_reboot', 'last_reconfig', 'current_server' ] as $field ) {
                if( isset( $data['status'][ $field ] ) && !str_contains( $data['status'][ $field ], 'T' ) ) {
                    $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $data['status'][ $field ] );
                    if( $dt ) {
                        $data['status'][ $field ] = $dt->format( 'Y-m-d\TH:i:sO' );
                    }
                }
            }
        }

        // Normalize protocol data: Birdwatcher returns uppercase state ("UP"/"DOWN"),
        // templates expect lowercase ("up"/"down"). Also ensure routes sub-fields exist.
        if( isset( $data['protocols'] ) && is_array( $data['protocols'] ) ) {
            foreach( $data['protocols'] as &$proto ) {
                // Lowercase state: "UP" → "up", "DOWN" → "down"
                if( isset( $proto['state'] ) ) {
                    $proto['state'] = strtolower( $proto['state'] );
                }

                // Ensure routes has expected sub-fields
                if( !isset( $proto['routes'] ) || !is_array( $proto['routes'] ) || empty( $proto['routes'] ) ) {
                    $proto['routes'] = [ 'imported' => 0, 'exported' => 0, 'preferred' => 0, 'filtered' => 0 ];
                } else {
                    $proto['routes'] = array_merge(
                        [ 'imported' => 0, 'exported' => 0, 'preferred' => 0, 'filtered' => 0 ],
                        $proto['routes']
                    );
                }

                // Ensure route_limit_at exists if import_limit is set
                if( isset( $proto['import_limit'] ) && !isset( $proto['route_limit_at'] ) ) {
                    $proto['route_limit_at'] = $proto['routes']['imported'];
                }
            }
            unset( $proto );
        }

        // Also normalize single protocol (from bgpNeighbourSummary)
        if( isset( $data['protocol'] ) && is_array( $data['protocol'] ) ) {
            if( isset( $data['protocol']['state'] ) ) {
                $data['protocol']['state'] = strtolower( $data['protocol']['state'] );
            }
        }

        return json_encode( $data );
    }

    /**
     * Get BGP Summary information as JSON
     *
     * @return string
     */
    #[\Override]
    public function bgpSummary(): string
    {
        return $this->apiCall( 'protocols/bgp' );
    }

    /**
     * Get BGP neighbour information as JSON
     *
     * Birdwatcher doesn't have a single-protocol endpoint like Birdseye's /protocol/{name}.
     * Instead, we fetch all BGP protocols and filter to the requested one, returning
     * a response structure compatible with what IXP Manager expects.
     *
     * @param string $protocol Protocol name
     * @return string
     */
    #[\Override]
    public function bgpNeighbourSummary( string $protocol ): string
    {
        $allProtocols = $this->apiCall( 'protocols/bgp' );

        if( empty( $allProtocols ) ) {
            return "";
        }

        $data = json_decode( $allProtocols, true );

        if( !$data || !isset( $data['protocols'] ) ) {
            return "";
        }

        // Filter to just the requested protocol
        if( isset( $data['protocols'][ $protocol ] ) ) {
            $data['protocol'] = $data['protocols'][ $protocol ];
            $data['protocol']['name'] = $protocol;
        } else {
            $data['protocol'] = [];
        }

        unset( $data['protocols'] );

        return json_encode( $data );
    }

    /**
     * Get the router's status as JSON
     *
     * @return string
     */
    #[\Override]
    public function status(): string
    {
        return $this->apiCall( 'status' );
    }

    /**
     * Get internal symbols.
     *
     * Particularly we're interested in route tables / vrfs and protocols.
     *
     * @return string
     */
    #[\Override]
    public function symbols(): string
    {
        return $this->apiCall( 'symbols' );
    }

    /**
     * Get routes for a named routing table (aka. vrf)
     * @param string $table Table name
     * @return string
     */
    #[\Override]
    public function routesForTable( string $table ): string
    {
        return $this->apiCall( 'routes/table/' . urlencode( $table ) );
    }

    /**
     * Get routes learnt from named protocol (e.g. BGP session)
     *
     * @param string $protocol Protocol name
     *
     * @return string
     */
    #[\Override]
    public function routesForProtocol( string $protocol ): string
    {
        return $this->apiCall( 'routes/protocol/' . urlencode( $protocol ) );
    }

    /**
     * Get routes exported to named protocol (e.g. BGP session)
     *
     * Birdwatcher doesn't have a /routes/export/ endpoint.
     * Use /routes/table/ for the protocol's table as the closest equivalent.
     *
     * @param string $protocol Protocol name
     *
     * @return string
     */
    #[\Override]
    public function routesForExport( string $protocol ): string
    {
        // Birdwatcher has no export route list — fetch from the protocol's table instead.
        // First get the protocol info to find its table name.
        $allProtocols = $this->apiCall( 'protocols/bgp' );
        $data = json_decode( $allProtocols, true );

        if( $data && isset( $data['protocols'][ $protocol ]['table'] ) ) {
            return $this->apiCall( 'routes/table/' . urlencode( $data['protocols'][ $protocol ]['table'] ) );
        }

        // Fallback — return the protocol's own routes
        return $this->apiCall( 'routes/protocol/' . urlencode( $protocol ) );
    }

    /**
     * Get details for a specific route as received by a protocol
     *
     * @param string    $protocol   Protocol name
     * @param string    $network    The route to lookup
     * @param int       $mask       The mask of the route to look up
     *
     * @return string
     */
    #[\Override]
    public function protocolRoute( string $protocol, string $network, int $mask ): string
    {
        // Birdwatcher doesn't have a single-route lookup endpoint.
        // Fetch all routes from the protocol and filter to the requested prefix.
        return $this->filterRoutesToPrefix(
            $this->apiCall( 'routes/protocol/' . urlencode( $protocol ) ),
            $network, $mask
        );
    }

    /**
     * Get details for a specific route in a named table (vrf)
     *
     * @param string    $table      Table name
     * @param string    $network    The route to lookup
     * @param int       $mask       The mask of the route to look up
     *
     * @return string
     */
    #[\Override]
    public function protocolTable( string $table, string $network, int $mask ): string
    {
        // Birdwatcher doesn't have a single-route lookup endpoint.
        // Fetch all routes from the table and filter to the requested prefix.
        return $this->filterRoutesToPrefix(
            $this->apiCall( 'routes/table/' . urlencode( $table ) ),
            $network, $mask
        );
    }

    /**
     * Get details for a specific route in a named protocol export
     *
     * @param string    $protocol   Protocol name
     * @param string    $network    The route to lookup
     * @param int       $mask       The mask of the route to look up
     *
     * @return string
     */
    #[\Override]
    public function exportRoute( string $protocol, string $network, int $mask ): string
    {
        // Birdwatcher doesn't have a /routes/export/ endpoint.
        // Look up the protocol's table and filter to the requested prefix.
        $allProtocols = $this->apiCall( 'protocols/bgp' );
        $data = json_decode( $allProtocols, true );

        $table = $data['protocols'][ $protocol ]['table'] ?? 'master';
        return $this->filterRoutesToPrefix(
            $this->apiCall( 'routes/table/' . urlencode( $table ) ),
            $network, $mask
        );
    }

    /**
     * Filter a routes response to only include routes matching a specific prefix.
     *
     * @param string $routesJson JSON response containing a 'routes' array
     * @param string $network    Network address (e.g. "119.252.92.0")
     * @param int    $mask       Prefix length (e.g. 23)
     * @return string Filtered JSON with only matching routes
     */
    private function filterRoutesToPrefix( string $routesJson, string $network, int $mask ): string
    {
        $data = json_decode( $routesJson, true );

        if( !$data || !isset( $data['routes'] ) || !is_array( $data['routes'] ) ) {
            return $routesJson;
        }

        // Normalize the target network address for comparison (handles IPv6 representation differences)
        $targetBin = @inet_pton( $network );

        $data['routes'] = array_values( array_filter( $data['routes'], function( $route ) use ( $network, $mask, $targetBin ) {
            if( !isset( $route['network'] ) ) {
                return false;
            }

            // Try exact string match first
            $prefix = $network . '/' . $mask;
            if( $route['network'] === $prefix ) {
                return true;
            }

            // Parse route's network and compare normalized addresses
            if( preg_match( '#^(.+)/(\d+)$#', $route['network'], $m ) ) {
                $routeBin = @inet_pton( $m[1] );
                if( $routeBin !== false && $targetBin !== false && $routeBin === $targetBin && (int)$m[2] === $mask ) {
                    return true;
                }
            }

            return false;
        }));

        return json_encode( $data );
    }

    /**
     * Get wildcard large communities in protocol table of form ( x, y, * )
     *
     * Birdwatcher doesn't have Birdseye's lc-zwild endpoint. However, when
     * y=1101 (filtering reasons), we can use Birdwatcher's /routes/filtered/
     * endpoint which returns the same rejected routes — they will still carry
     * the (ASN, 1101, reason) large communities that the caller parses.
     *
     * For other y values, we fall back to fetching all routes for the protocol
     * and filtering client-side by large community.
     *
     * @param string    $protocol Protocol name
     * @param int       $x
     * @param int       $y
     *
     * @return string
     */
    #[\Override]
    public function routesProtocolLargeCommunityWildXYRoutes( string $protocol, int $x, int $y ): string
    {
        if( $y === 1101 ) {
            // Filtered/rejected routes — use Birdwatcher's native filtered endpoint
            return $this->apiCall( 'routes/filtered/' . urlencode( $protocol ) );
        }

        // For other community queries (e.g. 1000=RPKI, 1001=IRRDB info),
        // fetch all routes and filter client-side by large community
        $allRoutes = $this->apiCall( 'routes/protocol/' . urlencode( $protocol ) );

        if( empty( $allRoutes ) ) {
            return json_encode( [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ] );
        }

        $data = json_decode( $allRoutes, true );

        if( !$data || !isset( $data['routes'] ) ) {
            return json_encode( [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ] );
        }

        // Filter routes that have a large community matching (x, y, *)
        $filtered = [];
        foreach( $data['routes'] as $route ) {
            $lcs = $route['bgp']['large_communities'] ?? [];
            foreach( $lcs as $lc ) {
                if( is_array( $lc ) && count( $lc ) >= 3 && (int)$lc[0] === $x && (int)$lc[1] === $y ) {
                    $filtered[] = $route;
                    break;
                }
            }
        }

        $data['routes'] = $filtered;
        return json_encode( $data );
    }

    // =========================================================================
    // Birdwatcher-specific endpoints (not in Birdseye)
    // =========================================================================

    /**
     * Get filtered (rejected) routes for a protocol
     *
     * This is a Birdwatcher-only feature. Shows routes that were rejected by
     * filters, which is very useful for debugging why a member's routes aren't
     * being accepted by the route server.
     *
     * @param string $protocol Protocol name
     * @return string
     */
    public function routesFiltered( string $protocol ): string
    {
        return $this->apiCall( 'routes/filtered/' . urlencode( $protocol ) );
    }

    /**
     * Get non-exported routes for a protocol
     *
     * Shows routes that exist but are not being exported to a peer.
     *
     * @param string $protocol Protocol name
     * @return string
     */
    public function routesNoExport( string $protocol ): string
    {
        return $this->apiCall( 'routes/noexport/' . urlencode( $protocol ) );
    }

    /**
     * Get routes by peer IP address
     *
     * @param string $peer Peer IP address
     * @return string
     */
    public function routesForPeer( string $peer ): string
    {
        return $this->apiCall( 'routes/peer/' . urlencode( $peer ) );
    }

    /**
     * Get route count for a protocol
     *
     * @param string $protocol Protocol name
     * @return string
     */
    public function routeCountForProtocol( string $protocol ): string
    {
        return $this->apiCall( 'routes/count/protocol/' . urlencode( $protocol ) );
    }

    /**
     * Get primary route count for a protocol
     *
     * @param string $protocol Protocol name
     * @return string
     */
    public function routeCountPrimaryForProtocol( string $protocol ): string
    {
        return $this->apiCall( 'routes/count/primary/' . urlencode( $protocol ) );
    }

    /**
     * Search routes by prefix
     *
     * @param string $prefix The prefix to search for
     * @return string
     */
    public function routesByPrefix( string $prefix ): string
    {
        return $this->apiCall( 'routes/prefix?prefix=' . urlencode( $prefix ) );
    }

    /**
     * Get filtered routes in a table
     *
     * @param string $table Table name
     * @return string
     */
    public function routesFilteredForTable( string $table ): string
    {
        return $this->apiCall( 'routes/table/' . urlencode( $table ) . '/filtered' );
    }

    /**
     * Get routes in a table filtered by peer
     *
     * @param string $table Table name
     * @param string $peer Peer IP address
     * @return string
     */
    public function routesForTableAndPeer( string $table, string $peer ): string
    {
        return $this->apiCall( 'routes/table/' . urlencode( $table ) . '/peer/' . urlencode( $peer ) );
    }
}
