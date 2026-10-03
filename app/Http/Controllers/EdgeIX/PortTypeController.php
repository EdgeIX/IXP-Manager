<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;
use IXP\Models\Location;
use IXP\Models\PortType;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * EdgeIX admin: sellable port-type catalogue CRUD (ordering Phase 3).
 *
 * The catalogue drives transceiver-detection mapping and order-form
 * choices. Adding a new sellable type (e.g. 25G) is a row here, not code.
 * Superuser-only (web-auth-superuser.php). See docs/ordering.md.
 */
class PortTypeController extends Controller
{
    public function index(): View
    {
        return view( 'porttype.index', [
            'portTypes' => PortType::withCount( [ 'switchPorts', 'offeredLocations' ] )->orderBy( 'priority' )->orderBy( 'speed' )->get(),
        ] );
    }

    public function create(): View
    {
        return view( 'porttype.edit', [
            'portType'     => null,
            'ptLocations'  => Location::orderBy( 'name' )->get(),
            'ptOfferedIds' => [],
        ] );
    }

    public function store( Request $r ): RedirectResponse
    {
        $data = $this->checkForm( $r );

        $pt = PortType::create( $data );
        $pt->offeredLocations()->sync( $r->input( 'offered_locations', [] ) );

        AlertContainer::push( "Port type <em>" . e( $pt->name ) . "</em> created.", Alert::SUCCESS );
        return redirect()->route( 'port-type@index' );
    }

    public function edit( PortType $portType ): View
    {
        return view( 'porttype.edit', [
            'portType'     => $portType,
            'ptLocations'  => Location::orderBy( 'name' )->get(),
            'ptOfferedIds' => $portType->offeredLocations()->pluck( 'location.id' )->all(),
        ] );
    }

    public function update( Request $r, PortType $portType ): RedirectResponse
    {
        $data = $this->checkForm( $r, $portType );

        $portType->update( $data );
        $portType->offeredLocations()->sync( $r->input( 'offered_locations', [] ) );

        AlertContainer::push( "Port type <em>" . e( $portType->name ) . "</em> updated.", Alert::SUCCESS );
        return redirect()->route( 'port-type@index' );
    }

    public function delete( PortType $portType ): RedirectResponse
    {
        if( $portType->switchPorts()->exists() ) {
            AlertContainer::push( "Cannot delete <em>" . e( $portType->name ) . "</em> — switch ports are mapped to it. Mark it inactive instead.", Alert::DANGER );
            return redirect()->route( 'port-type@index' );
        }

        $portType->delete();

        AlertContainer::push( "Port type <em>" . e( $portType->name ) . "</em> deleted.", Alert::SUCCESS );
        return redirect()->route( 'port-type@index' );
    }

    /**
     * Validate + normalise the form. Every match pattern line must be a
     * valid regex — a broken pattern must never reach the detection run.
     */
    private function checkForm( Request $r, ?PortType $portType = null ): array
    {
        $data = $r->validate( [
            'name'                => 'required|string|max:64|unique:port_type,name' . ( $portType ? ',' . $portType->id : '' ),
            'speed'               => 'required|integer|min:1',
            'priority'            => 'required|integer|min:1|max:9999',
            'active'              => 'nullable|boolean',
            'match_patterns'      => 'nullable|string|max:65535',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'notes'               => 'nullable|string|max:65535',
            'offered_locations'   => 'nullable|array',
            'offered_locations.*' => 'integer|exists:location,id',
        ] );

        unset( $data['offered_locations'] );

        $data['active'] = (bool)( $data['active'] ?? false );

        foreach( preg_split( '/\r\n|\r|\n/', (string)( $data['match_patterns'] ?? '' ) ) as $i => $line ) {
            $line = trim( $line );
            if( $line !== '' && @preg_match( '/' . str_replace( '/', '\/', $line ) . '/i', 'test' ) === false ) {
                throw new HttpResponseException( redirect()->back()->withInput()->withErrors( [
                    'match_patterns' => 'Line ' . ( $i + 1 ) . ' is not a valid regular expression: ' . $line,
                ] ) );
            }
        }

        return $data;
    }
}
