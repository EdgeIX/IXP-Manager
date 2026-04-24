<?php

namespace IXP\Http\Controllers\Api\V4;

use Illuminate\Http\JsonResponse;

use IXP\Http\Controllers\Controller;
use IXP\Models\Location;

/**
 * Facilities API (requires API key — superuser auth).
 *
 * Endpoints:
 *   GET /api/v4/facilities          — list all active facilities
 *   GET /api/v4/facilities/{id}     — single facility details
 *
 * Query parameters:
 *   ?active=1  (default) — only facilities with active switches
 *   ?active=0            — all facilities including empty/planned
 */
class FacilityController extends Controller
{
    /**
     * List facilities.
     */
    public function index(): JsonResponse
    {
        $activeOnly = request()->boolean( 'active', true );

        $query = Location::query()->orderBy( 'name' );

        if ( $activeOnly ) {
            $query->whereHas( 'cabinets.switcher' );
        }

        $facilities = $query->get()->map( fn( Location $l ) => $this->formatFacility( $l ) );

        return response()->json( [
            'facilities' => $facilities,
            'count'      => $facilities->count(),
        ] );
    }

    /**
     * Single facility detail.
     */
    public function show( int $id ): JsonResponse
    {
        $location = Location::findOrFail( $id );

        return response()->json( $this->formatFacility( $location, true ) );
    }

    /**
     * Format a facility for API output.
     */
    private function formatFacility( Location $l, bool $detailed = false ): array
    {
        $data = [
            'id'               => $l->id,
            'name'             => $l->name,
            'shortname'        => $l->shortname,
            'tag'              => $l->tag,
            'city'             => $l->city,
            'country'          => $l->country,
            'address'          => $l->address,
            'pdb_facility_id'  => $l->pdb_facility_id,
            'active'           => $l->cabinets()->whereHas( 'switcher' )->exists(),
        ];

        if ( $detailed ) {
            $data['nocemail']     = $l->nocemail;
            $data['nocphone']     = $l->nocphone;
            $data['officephone']  = $l->officephone;
            $data['officeemail']  = $l->officeemail;
            $data['notes']        = $l->notes;

            // Switches at this facility
            $switches = [];
            foreach ( $l->cabinets as $cab ) {
                foreach ( $cab->switcher ?? [] as $sw ) {
                    $switches[] = [
                        'name'           => $sw->name,
                        'infrastructure' => $sw->infrastructureModel?->name,
                        'active'         => (bool) $sw->active,
                    ];
                }
            }
            $data['switches'] = $switches;
        }

        return $data;
    }
}
