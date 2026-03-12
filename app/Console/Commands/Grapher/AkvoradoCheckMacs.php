<?php

namespace IXP\Console\Commands\Grapher;

use Illuminate\Console\Command;

use IXP\Models\{
    Layer2Address,
    MacAddress,
    Vlan,
    VlanInterface
};

use IXP\Services\Akvorado\AkvoradoService;

class AkvoradoCheckMacs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'akvorado:check-macs
                    {--period=6h : Lookback period (e.g. 1h, 6h, 24h)}
                    {--vlan= : Check a specific VLAN ID only}
                    {--include-learned : Also check against learned MACs (macaddress table)}
                    {--json : Output as JSON for automation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect rogue/unknown MAC addresses seen in Akvorado sFlow data';

    /**
     * Execute the console command.
     */
    public function handle( AkvoradoService $akvorado ): int
    {
        $period  = $this->option( 'period' );
        $asJson  = $this->option( 'json' );

        // Load all configured MACs from l2address table (normalised to lowercase colon-separated)
        $knownMacs = $this->loadConfiguredMacs();

        if( $this->option( 'include-learned' ) ) {
            $knownMacs = array_merge( $knownMacs, $this->loadLearnedMacs() );
        }

        if( !$asJson ) {
            $this->info( "Checking for unknown MACs (period: {$period}, known MACs: " . count( $knownMacs ) . ")" );
        }

        // Get VLANs to check
        $vlans = Vlan::publicOnly()
            ->when( $this->option( 'vlan' ), fn( $q, $id ) => $q->where( 'id', $id ) )
            ->get();

        $allRogues = [];

        foreach( $vlans as $vlan ) {
            $rogues = $this->checkVlan( $akvorado, $vlan, $period, $knownMacs );

            if( !empty( $rogues ) ) {
                $allRogues[ $vlan->name ] = $rogues;
            }
        }

        if( $asJson ) {
            $this->line( json_encode( $allRogues, JSON_PRETTY_PRINT ) );
        } else {
            $this->renderResults( $allRogues );
        }

        return empty( $allRogues ) ? 0 : 1;
    }

    /**
     * Check a single VLAN for unknown MACs.
     *
     * @return array [ [ 'mac' => ..., 'vlan' => ..., 'avg_bps' => ... ], ... ]
     */
    private function checkVlan( AkvoradoService $akvorado, Vlan $vlan, string $period, array $knownMacs ): array
    {
        // Parse period string to start/end
        $now   = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $start = clone $now;

        if( preg_match( '/^(\d+)([hd])$/', $period, $m ) ) {
            $start->modify( $m[2] === 'h' ? "-{$m[1]} hours" : "-{$m[1]} days" );
        } else {
            $start->modify( '-6 hours' );
        }

        // Query Akvorado for all SrcMACs seen on this VLAN
        $filter = "SrcVlan = {$vlan->number} AND InIfBoundary = external";

        $data = $akvorado->queryDateRange(
            $filter,
            $start->format( 'c' ),
            $now->format( 'c' ),
            'l3bps',
            [ 'SrcMAC' ],
            1000,
            10   // few data points — we only care about the dimension rows
        );

        $rogues = [];

        foreach( ( $data['rows'] ?? [] ) as $i => $row ) {
            $mac = strtolower( $row[0] ?? '' );

            if( $mac === '' || $mac === 'other' ) {
                continue;
            }

            if( isset( $knownMacs[ $mac ] ) ) {
                continue;
            }

            $avgBps = (float) ( $data['average'][$i] ?? 0 );
            $maxBps = (float) ( $data['max'][$i]     ?? 0 );

            $rogues[] = [
                'mac'     => $mac,
                'vlan'    => $vlan->number,
                'avg_bps' => $avgBps,
                'max_bps' => $maxBps,
            ];
        }

        // Sort by avg_bps descending (noisiest rogues first)
        usort( $rogues, fn( $a, $b ) => $b['avg_bps'] <=> $a['avg_bps'] );

        return $rogues;
    }

    /**
     * Load all configured MACs from l2address table.
     *
     * @return array [ 'aa:bb:cc:dd:ee:ff' => vli_id, ... ]
     */
    private function loadConfiguredMacs(): array
    {
        $macs = [];

        Layer2Address::all()->each( function( $l2a ) use ( &$macs ) {
            $mac = strtolower( implode( ':', str_split(
                str_replace( [ ':', '-', '.' ], '', $l2a->mac ), 2
            ) ) );
            $macs[ $mac ] = $l2a->vlan_interface_id;
        } );

        return $macs;
    }

    /**
     * Load learned MACs from macaddress table.
     *
     * @return array [ 'aa:bb:cc:dd:ee:ff' => vi_id, ... ]
     */
    private function loadLearnedMacs(): array
    {
        $macs = [];

        MacAddress::all()->each( function( $ma ) use ( &$macs ) {
            $mac = strtolower( implode( ':', str_split(
                str_replace( [ ':', '-', '.' ], '', $ma->mac ), 2
            ) ) );
            $macs[ $mac ] = $ma->virtualinterfaceid;
        } );

        return $macs;
    }

    /**
     * Render results as a table to console.
     */
    private function renderResults( array $allRogues ): void
    {
        if( empty( $allRogues ) ) {
            $this->info( 'No unknown MACs detected.' );
            return;
        }

        $totalRogues = 0;

        foreach( $allRogues as $vlanName => $rogues ) {
            $this->warn( "\n{$vlanName}: " . count( $rogues ) . " unknown MAC(s)" );

            $rows = [];
            foreach( $rogues as $r ) {
                $rows[] = [
                    $r['mac'],
                    $r['vlan'],
                    $this->formatBps( $r['avg_bps'] ),
                    $this->formatBps( $r['max_bps'] ),
                ];
                $totalRogues++;
            }

            $this->table( [ 'MAC Address', 'VLAN', 'Avg Rate', 'Max Rate' ], $rows );
        }

        $this->error( "\nTotal: {$totalRogues} unknown MAC(s) detected across " . count( $allRogues ) . " VLAN(s)" );
    }

    /**
     * Format bits/s with SI prefix.
     */
    private function formatBps( float $bps ): string
    {
        if( $bps >= 1e12 ) return round( $bps / 1e12, 2 ) . ' Tbps';
        if( $bps >= 1e9 )  return round( $bps / 1e9, 2 )  . ' Gbps';
        if( $bps >= 1e6 )  return round( $bps / 1e6, 2 )  . ' Mbps';
        if( $bps >= 1e3 )  return round( $bps / 1e3, 2 )  . ' Kbps';
        return round( $bps, 2 ) . ' bps';
    }
}
