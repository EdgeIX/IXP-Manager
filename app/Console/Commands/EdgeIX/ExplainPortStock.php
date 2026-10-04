<?php

namespace IXP\Console\Commands\EdgeIX;

use Illuminate\Console\Command;

use IXP\Models\PatchPanelPort;
use IXP\Models\PortOrder;
use IXP\Models\PortOrderPort;
use IXP\Models\Switcher;
use IXP\Models\SwitchPort;

use IXP\Services\EdgeIX\PortStockService;

/**
 * EdgeIX ordering: explain, per port, why it is or isn't sellable stock —
 * the debugging tool for "the stock page says N but I prewired more".
 *
 * Walks EVERY port on the switch (including ones the stock page's base
 * query filters out, e.g. ports not typed Peering or inactive) and prints
 * the FIRST failing sellable condition, in evaluation order:
 *
 *   switch active → port active → port use = Peering → optic classified
 *   → catalogue type active → not in service → not reserved → panel
 *   linked → panel Prewired → site not excluded → type offered at site
 *
 * Usage: php artisan port-stock:explain pe1akl1
 *        php artisan port-stock:explain pe1akl1 --only-failing
 */
class ExplainPortStock extends Command
{
    protected $signature = 'port-stock:explain
                            {switch : The switch name}
                            {--only-failing : Hide ports that are sellable or in service}';

    protected $description = 'EdgeIX: per-port explanation of why each port is / is not sellable stock';

    public function handle( PortStockService $stock ): int
    {
        $s = Switcher::where( 'name', $this->argument( 'switch' ) )->first();
        if( !$s ) {
            $this->error( 'No switch named ' . $this->argument( 'switch' ) );
            return self::FAILURE;
        }

        $offered  = $stock->offeredMap();
        $excluded = array_flip( config( 'ordering.excluded_locations', [] ) );
        $reserved = PortOrderPort::whereHas( 'portOrder', fn( $q ) =>
                $q->whereIn( 'state', PortOrder::OPEN_STATES ) )
            ->pluck( 'switchportid' )->flip();

        $rows = [];

        $ports = $s->switchPorts()
            ->with( [ 'patchPanelPort.patchPanel.cabinet.location', 'physicalInterface', 'portType', 'portTypeOverride' ] )
            ->orderBy( 'id' )->get()
            ->filter( fn( SwitchPort $sp ) => $sp->ifName && !str_contains( $sp->ifName, '.' ) );

        foreach( $ports as $sp ) {
            $type     = $sp->effectivePortType();
            $ppp      = $sp->patchPanelPort;
            $panelLoc = $ppp?->patchPanel?->cabinet?->location;
            $loc      = $panelLoc ?? $s->cabinet?->location;

            $verdict = match( true ) {
                !$s->active
                    => 'switch inactive',
                !$sp->active
                    => 'port inactive',
                (int)$sp->type !== SwitchPort::TYPE_PEERING
                    => 'port use is "' . ( SwitchPort::$TYPES[ $sp->type ] ?? $sp->type ) . '" (needs Peering)',
                !$type && $sp->detected_xcvr
                    => 'optic detected but no catalogue match',
                !$type
                    => 'no optic detected / no port type',
                !$type->active
                    => 'catalogue type "' . $type->name . '" is inactive (classify-only)',
                (bool)$sp->physicalInterface
                    => 'IN SERVICE',
                isset( $reserved[ $sp->id ] )
                    => 'reserved by an open order',
                !$ppp
                    => 'no patch panel port linked',
                (int)$ppp->state !== PatchPanelPort::STATE_PREWIRED
                    => 'panel state "' . ( PatchPanelPort::$STATES[ $ppp->state ] ?? $ppp->state ) . '" (needs Prewired)',
                $loc && isset( $excluded[ $loc->id ] )
                    => 'site excluded from self-serve (ORDER_EXCLUDED_LOCATIONS)',
                !( !isset( $offered[ $type->id ] ) || ( $loc && isset( $offered[ $type->id ][ $loc->id ] ) ) )
                    => 'type not offered at this site (Offered-at map)',
                default
                    => 'SELLABLE',
            };

            if( $this->option( 'only-failing' ) && in_array( $verdict, [ 'SELLABLE', 'IN SERVICE' ], true ) ) {
                continue;
            }

            $rows[] = [
                $sp->ifName,
                $type?->name ?? ( $sp->detected_xcvr ?: '—' ),
                $ppp ? ( ( $ppp->patchPanel?->name ?? 'panel' ) . ' #' . $ppp->number ) : '—',
                $loc?->name ?? '—',
                $verdict,
            ];
        }

        $this->table( [ 'Port', 'Type / optic', 'Panel port', 'Site (demarc)', 'Verdict' ], $rows );

        $sellable = collect( $rows )->where( 4, 'SELLABLE' )->count();
        $this->info( "{$sellable} sellable on {$s->name}. Fix the first-failing condition shown per port." );

        return self::SUCCESS;
    }
}
