<?php

namespace IXP\Http\Controllers\Services;

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

use Auth, ErrorException;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;
use Illuminate\Http\{
    RedirectResponse,
    Request,
    Response
};

use Illuminate\Routing\Redirector;

use Illuminate\View\View;

use IXP\Contracts\LookingGlass as LookingGlassContract;

use IXP\Exceptions\Services\LookingGlass\GeneralException as LookingGlassGeneralException;

use IXP\Http\Controllers\Controller;

use IXP\Models\{
    Aggregators\RouterAggregator,
    Customer,
    Router,
    User
};

/**
 * LookingGlass Controller
 *
 * *************************************************
 * ***********      SECURITY NOTICE      ***********
 * *************************************************
 *
 * IF WE GET TO THIS CONTROLLER, WE CAN ASSUME THE
 * REQUEST HAS BEEN VALIDATED AND VERIFIED.
 *
 * THE LookingGlass MIDDLEWARE IS RESPONSIBLE FOR
 * SECURITY AND PARAMETER CHECKS
 *
 * *************************************************
 *
 * @author     Barry O'Donovan   <barry@islandbridgenetworks.ie>
 * @author     Yann Robin        <yann@islandbridgenetworks.ie>
 * @category   IXP
 * @package    IXP\Services\LookingGlass
 * @copyright  Copyright (C) 2009 - 2020 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class LookingGlass extends Controller
{
    /**
     * the LookingGlass
     *
     * @var LookingGlassContract
     */
    private $lg = null;

    /**
     * The request object
     *
     * @var Request $request
     */
    private $request = null;

    /**
     * Constructor
     *
     * @param Request $request
     */
    public function __construct( Request $request )
    {
        // NB: Constructor happens before middleware...
        $this->request = $request;
    }

    /**
     * Looking glass accessor
     *
     * @return LookingGlassContract
     *
     * @throws
     */
    private function lg(): LookingGlassContract
    {
        if( $this->lg === null ) {
            $this->lg = $this->request()->attributes->get('lg' );
            // if there's no graph then the middleware went wrong... safety net:
            if( $this->lg === null ) {
                throw new LookingGlassGeneralException('Middleware could not load looking glass but did not throw a 404' );
            }
        }
        return $this->lg;
    }

    /**
     * Request accessor
     *
     * @return Request
     */
    private function request(): Request
    {
        return $this->request;
    }

    /**
     * Add view parameters common for all requests.
     *
     * @param View $view
     *
     * @return View
     *
     * @throws
     */
    private function addCommonParams( View $view ): View
    {
        $cust = Auth::check() ? Customer::find( Auth::getUser()->custid ) : null;
        $user = Auth::check() ? User::find( Auth::id() ) : null;

        $view->with( 'status',      json_decode( $this->lg()->status(), false, 512, JSON_THROW_ON_ERROR));
        $view->with( 'lg',          $this->lg() );
        $view->with( 'routers',     RouterAggregator::forDropdown( $cust, $user ) );
        $view->with( 'tabRouters',  RouterAggregator::forTab( $cust, $user ) );
        return $view;
    }

    /**
     * Index page
     *
     * @return View
     *
     * @throws
     */
    public function index(): View
    {
        $cust = Auth::check() ? Customer::find( Auth::getUser()->custid ) : null;
        $user = Auth::check() ? User::find( Auth::id() ) : null;

        return view('services/lg/index' )->with( [
            'lg'            => false,
            'routers'       => RouterAggregator::forDropdown( $cust, $user ),
            'tabRouters'    => RouterAggregator::forTab( $cust, $user )
        ] );
    }

    /**
     * Returns the router's status as JSON
     *
     * @param string $handle
     *
     * @return Response JSON of status
     *
     * @throws
     */
    public function status( string $handle ): Response
    {
        // get the router status
        return response()
            ->make( $this->lg()->status() )
            ->header('Content-Type', 'application/json' );
    }

    /**
     * Returns the router's "bgp summary" as JSON
     *
     * @param string $handle
     * @return Response JSON of status
     *
     * @throws
     */
    public function bgpSummaryApi( string $handle ): Response
    {
        // get the router status
        return response()
            ->make( $this->lg()->bgpSummary() )
            ->header('Content-Type', 'application/json');
    }

    /**
     * @param string $handle
     *
     * @return View
     *
     * @throws
     */
    public function bgpSummary(string $handle ): View
    {
        // get bgp protocol summary
        $view = view('services/lg/bgp-summary' )->with([
            'content' => json_decode( $this->lg()->bgpSummary(), false, 512, JSON_THROW_ON_ERROR),
        ]);

        return $this->addCommonParams( $view );
    }

    /**
     * @param string $handle
     * @param string $table
     *
     * @return RedirectResponse|View
     *
     * @throws
     */
    public function routesForTable( string $handle, string $table ): RedirectResponse|View
    {
        $tooManyRoutesMsg = "The routing table <code>{$table}</code> has too many routes to display in the web interface. Please use "
            . "<a href=\"" . route( 'lg::route-search', [ 'handle' => $this->lg()->router()->handle ] )
            . "\">the route search tool</a> to query this table.";

        try{
            $routes = $this->lg()->routesForTable( $table );
        } catch( ErrorException $e ) {
            if( strpos( $e->getMessage(), 'HTTP/1.0 403' ) !== false ) {
                return redirect( 'lg/' . $handle )->with( 'msg', $tooManyRoutesMsg );
            }
            return redirect( 'lg/' . $handle )->with('msg', 'An error occurred - please contact our support team if you wish.' );
        }

        if( $routes === "" ) {
            return redirect( 'lg/' . $handle )->with( 'msg', $tooManyRoutesMsg );
        }

        $view = view('services/lg/routes' )->with([
            'content'   => json_decode($routes, false, 512, JSON_THROW_ON_ERROR),
            'source'    => 'table', 'name' => $table,
            'peerName'  => null,
        ]);

        return $this->addCommonParams( $view );
    }

    /**
     * @param string $handle
     * @param string $protocol
     *
     * @throws
     */
    public function routesForProtocol( string $handle, string $protocol ): RedirectResponse|View
    {
        try{
            // get bgp protocol summary
            $view = view('services/lg/routes' )->with([
                'content'  => json_decode( $this->lg()->routesForProtocol( $protocol ), false, 512, JSON_THROW_ON_ERROR),
                'source'   => 'protocol', 'name' => $protocol,
                'peerName' => $this->peerName( $protocol ),
            ]);
            return $this->addCommonParams( $view );
        } catch( \Exception $e ){
            AlertContainer::push( 'The available resource is not available. Most likely the amount of routes exceed the APIs configured maximum threshold.', Alert::DANGER );
            return redirect( route( "lg::bgp-sum", [ 'handle' => $handle ] ) );
        }
    }

    /**
     * @param string $handle
     * @param string $protocol
     *
     * @return View
     *
     * @throws
     */
    public function routesForExport( string $handle, string $protocol ): View
    {
        // get bgp protocol summary
        $view = view('services/lg/routes' )->with([
            'content'   => json_decode( $this->lg()->routesForExport( $protocol ), false, 512, JSON_THROW_ON_ERROR),
            'source'    => 'export to protocol',
            'name'      => $protocol,
            'peerName'  => $this->peerName( $protocol ),
        ]);
        return $this->addCommonParams( $view );
    }

    /**
     * @param string $handle
     * @param string $network
     * @param string $mask
     * @param string $protocol
     *
     * @return View
     *
     * @throws
     */
    public function routeProtocol( string $handle, string $network, string $mask, string $protocol ): View
    {
        return view('services/lg/route' )->with([
            'content' => json_decode($this->lg()->protocolRoute($protocol, $network, (int) $mask), false, 512,
                JSON_THROW_ON_ERROR),
            'source'  => 'protocol',
            'name'    => $protocol,
            'lg'      => $this->lg(),
            'net' => urldecode( $network.'/'.$mask ),
        ]);
    }

    /**
     * @param string $handle
     * @param string $network
     * @param string $mask
     * @param string $table
     *
     * @return View
     *
     * @throws
     */
    public function routeTable( string $handle, string $network, string $mask, string $table ): View
    {
        return view('services/lg/route')->with( [
            'content' => json_decode( $this->lg()->protocolTable( $table, $network, (int)$mask ), false ),
            'source'  => 'table',
            'name'    => $table,
            'lg'      => $this->lg(),
            'net'     => urldecode($network . '/' . $mask),
        ]);
    }

    /**
     * @param string $handle
     * @param string $network
     * @param string $mask
     * @param string $protocol
     *
     * @return View
     *
     * @throws
     */
    public function routeExport( string $handle, string $network, string $mask, string $protocol ): View
    {
        return view('services/lg/route' )->with([
            'content'   => json_decode( $this->lg()->exportRoute( $protocol, $network, (int)$mask ), false ),
            'source'    => 'export',
            'name'      => $protocol,
            'lg'        => $this->lg(),
            'net'       => urldecode( $network . '/' . $mask ),
        ]);
    }

    /**
     * @param string $handle
     *
     * @return View
     *
     * @throws
     */
    public function routeSearch( string $handle ): View
    {
        $view = view('services/lg/route-search' )->with( [
            'content' => json_decode( $this->lg()->symbols(), false ),
        ]);
        return $this->addCommonParams( $view );
    }

    /**
     * Search routes by standard or large community.
     *
     * Accepts communities as colon-separated strings:
     *   Standard: "0:4826"
     *   Large:    "24224:0:4826"
     */
    public function routesByCommunity( string $handle, string $community ): RedirectResponse|View
    {
        try {
            $lg = $this->lg();
            $router = $lg->router();
            $parts = array_map( 'intval', explode( ':', $community ) );

            if( count( $parts ) < 2 || count( $parts ) > 3 ) {
                AlertContainer::push( 'Invalid community format. Use x:y for standard or x:y:z for large communities.', Alert::DANGER );
                return redirect( route( 'lg::route-search', [ 'handle' => $handle ] ) );
            }

            $masterTable = 'master';
            if( (int)$router->software === Router::SOFTWARE_BIRD2 || (int)$router->software === Router::SOFTWARE_BIRD3 ) {
                $masterTable = 'master' . substr( $router->protocol(), -1 );
            }

            $allRoutes = $lg->routesForTable( $masterTable );

            if( empty( $allRoutes ) ) {
                $data = [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ];
                \Log::info( "Community search: routesForTable({$masterTable}) returned empty" );
            } else {
                $data = json_decode( $allRoutes, true );
                if( !$data || !isset( $data['routes'] ) ) {
                    \Log::info( "Community search: routesForTable({$masterTable}) returned non-route data, length=" . strlen( $allRoutes ) );
                    $data = [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ];
                } else {
                    \Log::info( "Community search: got " . count( $data['routes'] ) . " routes from {$masterTable}" );
                }
            }

            $isLarge = count( $parts ) === 3;
            $matched = [];

            foreach( $data['routes'] as $route ) {
                if( $isLarge ) {
                    foreach( $route['bgp']['large_communities'] ?? [] as $lc ) {
                        if( is_array( $lc ) && count( $lc ) >= 3
                            && (int)$lc[0] === $parts[0] && (int)$lc[1] === $parts[1] && (int)$lc[2] === $parts[2] ) {
                            $matched[] = $route;
                            break;
                        }
                    }
                } else {
                    foreach( $route['bgp']['communities'] ?? [] as $c ) {
                        if( is_array( $c ) && count( $c ) >= 2
                            && (int)$c[0] === $parts[0] && (int)$c[1] === $parts[1] ) {
                            $matched[] = $route;
                            break;
                        }
                    }
                }
            }

            $data['routes'] = $matched;

            $view = view( 'services/lg/routes' )->with([
                'content'  => json_decode( json_encode( $data ), false ),
                'source'   => 'community search',
                'name'     => $community,
                'peerName' => null,
            ]);
            return $this->addCommonParams( $view );
        } catch( \Exception $e ) {
            AlertContainer::push( 'Could not search routes by community: ' . $e->getMessage(), Alert::DANGER );
            return redirect( route( 'lg::route-search', [ 'handle' => $handle ] ) );
        }
    }

    /**
     * Get filtered/rejected routes for a protocol.
     *
     * Birdwatcher: uses /routes/filtered/ endpoint
     * Birdseye: uses lc-zwild with (ASN, 1101, *) large communities
     */
    public function routesFiltered( string $handle, string $protocol ): RedirectResponse|View
    {
        try {
            $routes = $this->getFilteredRoutes( $protocol );
            $view = view('services/lg/routes' )->with([
                'content'  => json_decode( $routes, false, 512, JSON_THROW_ON_ERROR ),
                'source'   => 'filtered from protocol',
                'name'     => $protocol,
                'peerName' => $this->peerName( $protocol ),
            ]);
            return $this->addCommonParams( $view );
        } catch( \Exception $e ) {
            AlertContainer::push( 'Could not retrieve filtered routes.', Alert::DANGER );
            return redirect( route( 'lg::bgp-sum', [ 'handle' => $handle ] ) );
        }
    }

    /**
     * Get not-exported routes for a protocol (birdwatcher only).
     */
    public function routesNotExported( string $handle, string $protocol ): RedirectResponse|View
    {
        try {
            $routes = $this->getNotExportedRoutes( $protocol );
            $view = view('services/lg/routes' )->with([
                'content'  => json_decode( $routes, false, 512, JSON_THROW_ON_ERROR ),
                'source'   => 'not exported to protocol',
                'name'     => $protocol,
                'peerName' => $this->peerName( $protocol ),
            ]);
            return $this->addCommonParams( $view );
        } catch( \Exception $e ) {
            AlertContainer::push( 'Could not retrieve not-exported routes.', Alert::DANGER );
            return redirect( route( 'lg::bgp-sum', [ 'handle' => $handle ] ) );
        }
    }

    /**
     * API: filtered routes as JSON (for AJAX tab loading)
     */
    public function routesFilteredApi( string $handle, string $protocol ): Response
    {
        try {
            return response()
                ->make( $this->getFilteredRoutes( $protocol ) )
                ->header( 'Content-Type', 'application/json' );
        } catch( \Exception $e ) {
            return response()->json( [ 'routes' => [], 'error' => 'Could not retrieve filtered routes' ], 200 );
        }
    }

    /**
     * API: not-exported routes as JSON (for AJAX tab loading)
     */
    public function routesNotExportedApi( string $handle, string $protocol ): Response
    {
        try {
            return response()
                ->make( $this->getNotExportedRoutes( $protocol ) )
                ->header( 'Content-Type', 'application/json' );
        } catch( \Exception $e ) {
            return response()->json( [ 'routes' => [], 'error' => 'Could not retrieve not-exported routes' ], 200 );
        }
    }

    /**
     * Look up the peer description for a protocol name from BGP summary.
     */
    private function peerName( string $protocol ): ?string
    {
        try {
            $summary = json_decode( $this->lg()->bgpSummary(), false );
            if( isset( $summary->protocols->$protocol ) ) {
                $p = $summary->protocols->$protocol;
                return $p->description_short ?? $p->description ?? null;
            }
        } catch( \Exception $e ) {
            // Non-critical — just return null
        }
        return null;
    }

    /**
     * Fetch filtered routes from the appropriate backend.
     *
     * Uses large community (ASN, 1101, *) filtering for both backends.
     * Birdwatcher's /routes/filtered/ only returns routes BIRD rejected at import,
     * but IXP Manager route servers accept routes and tag them with (ASN, 1101, reason)
     * communities instead. So we use the community-based approach for both.
     */
    private function getFilteredRoutes( string $protocol ): string
    {
        return $this->lg()->routesProtocolLargeCommunityWildXYRoutes(
            $protocol, $this->lg()->router()->asn, 1101
        );
    }

    /**
     * Fetch not-exported routes — routes suppressed to this peer via no-announce communities.
     *
     * Checks for all no-announce community variants:
     *   Standard: (0, peer_asn)     — prevent announcement to this peer
     *   Standard: (0, RS_ASN)       — prevent announcement to ALL peers
     *   Large:    (RS_ASN, 0, peer_asn) — prevent announcement to this peer
     *   Large:    (RS_ASN, 0, 0)    — prevent announcement to ALL peers
     *
     * A route is considered "not exported" if it has any no-announce community
     * UNLESS it also has an explicit announce override:
     *   Standard: (RS_ASN, peer_asn) — announce to this peer
     *   Large:    (RS_ASN, 1, peer_asn) — announce to this peer
     *   Standard: (RS_ASN, RS_ASN)  — announce to ALL peers
     *   Large:    (RS_ASN, 1, 0)    — announce to ALL peers
     */
    private function getNotExportedRoutes( string $protocol ): string
    {
        $lg = $this->lg();
        $rsAsn = $lg->router()->asn;
        $router = $lg->router();

        // Get the peer ASN from BGP summary
        $peerAsn = $this->peerAsn( $protocol );
        if( !$peerAsn ) {
            return json_encode( [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ] );
        }

        // Fetch ALL routes from the master table — not just the peer's protocol.
        // Routes tagged with (0, peer_asn) are sent by OTHER peers, so we need
        // to search the entire routing table.
        $masterTable = 'master';
        if( (int)$router->software === Router::SOFTWARE_BIRD2 || (int)$router->software === Router::SOFTWARE_BIRD3 ) {
            $masterTable = 'master' . substr( $router->protocol(), -1 );
        }
        $allRoutes = $lg->routesForTable( $masterTable );

        if( empty( $allRoutes ) ) {
            return json_encode( [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ] );
        }

        $data = json_decode( $allRoutes, true );
        if( !$data || !isset( $data['routes'] ) ) {
            return json_encode( [ 'api' => [ 'version' => 'birdwatcher' ], 'routes' => [] ] );
        }

        $notExported = [];
        foreach( $data['routes'] as $route ) {
            $communities    = $route['bgp']['communities'] ?? [];
            $largeCommunities = $route['bgp']['large_communities'] ?? [];

            $suppressed = false;
            $overridden = false;

            // Check standard communities
            foreach( $communities as $c ) {
                if( !is_array( $c ) || count( $c ) < 2 ) continue;
                $a = (int)$c[0]; $b = (int)$c[1];

                // No-announce: (0, peer_asn) or (0, RS_ASN)
                if( $a === 0 && ( $b === $peerAsn || $b === $rsAsn ) ) {
                    $suppressed = true;
                }
                // Announce override: (RS_ASN, peer_asn) or (RS_ASN, RS_ASN)
                if( $a === $rsAsn && ( $b === $peerAsn || $b === $rsAsn ) ) {
                    $overridden = true;
                }
            }

            // Check large communities
            foreach( $largeCommunities as $lc ) {
                if( !is_array( $lc ) || count( $lc ) < 3 ) continue;
                $a = (int)$lc[0]; $b = (int)$lc[1]; $c = (int)$lc[2];

                if( $a !== $rsAsn ) continue;

                // No-announce: (RS_ASN, 0, peer_asn) or (RS_ASN, 0, 0)
                if( $b === 0 && ( $c === $peerAsn || $c === 0 ) ) {
                    $suppressed = true;
                }
                // Announce override: (RS_ASN, 1, peer_asn) or (RS_ASN, 1, 0)
                if( $b === 1 && ( $c === $peerAsn || $c === 0 ) ) {
                    $overridden = true;
                }
            }

            if( $suppressed && !$overridden ) {
                $notExported[] = $route;
            }
        }

        $data['routes'] = $notExported;
        return json_encode( $data );
    }

    /**
     * Look up the peer ASN for a protocol name from BGP summary.
     */
    private function peerAsn( string $protocol ): ?int
    {
        try {
            $summary = json_decode( $this->lg()->bgpSummary(), false );
            if( isset( $summary->protocols->$protocol->neighbor_as ) ) {
                return (int)$summary->protocols->$protocol->neighbor_as;
            }
        } catch( \Exception $e ) {
            // Non-critical
        }
        return null;
    }
}