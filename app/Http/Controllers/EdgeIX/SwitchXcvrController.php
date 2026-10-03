<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use OSS_SNMP\SNMP;

use IXP\Http\Controllers\Controller;

use IXP\Models\PortType;
use IXP\Models\Switcher;
use IXP\Models\SwitchPort;

use IXP\Services\EdgeIX\TransceiverDetector;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * EdgeIX admin: transceiver detection in the switch UI (ordering Phase 3).
 *
 * Sits in the per-switch dropdown alongside the upstream actions:
 *
 *   SNMP Actions     → "Detect Transceivers" (live): walks the ENTITY-MIB
 *                      right now via TransceiverDetector, shows what was
 *                      found + catalogue mapping + raw entity rows, and
 *                      offers "Save to database" — the UI equivalent of
 *                      `artisan switch:detect-transceivers` (same service,
 *                      same persist path).
 *   Database Actions → "Port Transceivers": what's stored per port
 *                      (detected optic, mapped type, admin override), with
 *                      per-port override editing.
 *
 * Superuser-only. See docs/ordering.md.
 */
class SwitchXcvrController extends Controller
{
    /**
     * Live ENTITY-MIB walk — read-only preview of what detection would do.
     */
    public function detect( Switcher $switch ): View|RedirectResponse
    {
        if( !$switch->snmppasswd || trim( $switch->snmppasswd ) === '' ) {
            AlertContainer::push( "No SNMP community set for " . e( $switch->name ) . " — cannot walk the ENTITY-MIB.", Alert::DANGER );
            return redirect( route( 'switch@list' ) );
        }

        $detector = new TransceiverDetector();

        try {
            $host    = new SNMP( $switch->hostname, $switch->snmppasswd );
            $results = $detector->detect( $switch, $host );
        } catch( \OSS_SNMP\Exception $e ) {
            AlertContainer::push( "OSS_SNMP exception walking the ENTITY-MIB on " . e( $switch->name ) . " — is the switch reachable?", Alert::DANGER );
            return redirect( route( 'switch@list' ) );
        }

        return view( 'xcvr.detect', [
            'xcvrSwitch'   => $switch,
            'xcvrDetected' => $results['detected'],
            'xcvrUnmapped' => $results['unmapped'],
            'xcvrEntities' => $detector->entities,
        ] );
    }

    /**
     * Re-walk and persist — the "Save to database" button on the live page.
     */
    public function apply( Switcher $switch ): RedirectResponse
    {
        if( !$switch->snmppasswd || trim( $switch->snmppasswd ) === '' ) {
            AlertContainer::push( "No SNMP community set for " . e( $switch->name ) . ".", Alert::DANGER );
            return redirect( route( 'switch@list' ) );
        }

        $detector = new TransceiverDetector();

        try {
            $host    = new SNMP( $switch->hostname, $switch->snmppasswd );
            $results = $detector->detect( $switch, $host );
        } catch( \OSS_SNMP\Exception $e ) {
            AlertContainer::push( "OSS_SNMP exception walking the ENTITY-MIB on " . e( $switch->name ) . " — nothing saved.", Alert::DANGER );
            return redirect( route( 'switch@list' ) );
        }

        $detector->persist( $switch, $results );

        $matched = collect( $results['detected'] )->filter( fn( $d ) => $d['type'] )->count();
        AlertContainer::push(
            "Transceiver detection saved for <em>" . e( $switch->name ) . "</em>: "
            . count( $results['detected'] ) . " optic(s) detected, {$matched} mapped to a port type.",
            Alert::SUCCESS
        );

        return redirect( route( 'switch-xcvr@list', [ 'switch' => $switch->id ] ) );
    }

    /**
     * Database view: stored detection + override per port of this switch.
     */
    public function list( Switcher $switch ): View
    {
        return view( 'xcvr.index', [
            'xcvrSwitch'    => $switch,
            'xcvrPorts'     => $switch->switchPorts()
                ->with( [ 'portType', 'portTypeOverride', 'physicalInterface', 'patchPanelPort' ] )
                ->orderBy( 'id' )->get(),
            'xcvrPortTypes' => PortType::orderBy( 'speed' )->orderBy( 'name' )->get(),
        ] );
    }

    /**
     * Set / clear the per-port admin port-type override.
     */
    public function setOverride( Request $r, SwitchPort $sp ): RedirectResponse
    {
        $r->validate( [ 'port_type_override_id' => 'nullable|integer|exists:port_type,id' ] );

        $sp->port_type_override_id = $r->port_type_override_id ?: null;
        $sp->save();

        AlertContainer::push(
            "Port type override for <em>" . e( $sp->ifName ) . "</em> "
            . ( $sp->port_type_override_id ? "set to <em>" . e( $sp->portTypeOverride->name ) . "</em>." : "cleared — detection applies." ),
            Alert::SUCCESS
        );

        return redirect( route( 'switch-xcvr@list', [ 'switch' => $sp->switchid ] ) );
    }
}
