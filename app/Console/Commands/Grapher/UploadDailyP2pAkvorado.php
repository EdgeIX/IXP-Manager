<?php

namespace IXP\Console\Commands\Grapher;

use Carbon\Carbon;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use IXP\Models\Aggregators\VlanInterfaceAggregator;
use IXP\Models\Customer;
use IXP\Models\P2pDailyStats;
use IXP\Models\VlanInterface;

use IXP\Services\Akvorado\AkvoradoService;
use IXP\Services\Grapher\Graph;

class UploadDailyP2pAkvorado extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'akvorado:upload-daily-p2p
                    {day? : Target day in YYYY-MM-DD format (defaults to yesterday)}
                    {--customer-id= : Process a single customer by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect daily P2P traffic stats from Akvorado and store in database';

    /**
     * Execute the console command.
     */
    public function handle( AkvoradoService $akvorado ): int
    {
        $day = $this->argument( 'day' ) ?? now()->subDay()->format( 'Y-m-d' );

        if( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
            $this->error( "Invalid day format — expected YYYY-MM-DD, e.g. " . now()->subDay()->format( 'Y-m-d' ) );
            return 1;
        }

        $startTime = microtime( true );
        $this->info( "Collecting P2P daily stats for {$day}" );

        $customers = Customer::currentActive( true, true, true )
            ->when( $this->option( 'customer-id' ), function( $q, $cid ) {
                $q->where( 'id', $cid );
            } )
            ->get();

        $totalStats = 0;

        foreach( $customers as $c ) {
            $iterTime = microtime( true );

            if( $this->getOutput()->isVerbose() ) {
                $this->line( "Processing {$c->name}" );
            }

            $stats = $this->collectForCustomer( $akvorado, $c, $day );

            if( !empty( $stats ) ) {
                $this->storeStats( $stats, $c->id, $day );
                $totalStats += count( $stats );
            }

            if( $this->getOutput()->isVerbose() ) {
                $peers = count( $stats );
                $elapsed = round( microtime( true ) - $iterTime, 2 );
                $this->line( "  → {$peers} peers in {$elapsed}s" );
            }
        }

        $elapsed = round( microtime( true ) - $startTime, 1 );
        $this->info( "Done: {$totalStats} peer records for " . count( $customers ) . " customers in {$elapsed}s" );

        return 0;
    }

    /**
     * Collect P2P stats for a single customer across all their VLIs.
     *
     * @return array [ peer_cust_id => [ 'ipv4_total_in' => int, ... ], ... ]
     */
    private function collectForCustomer( AkvoradoService $akvorado, Customer $c, string $day ): array
    {
        $stats = [];

        foreach( $c->virtualinterfaces as $vi ) {
            foreach( $vi->vlaninterfaces as $svli ) {
                if( !$svli->vlan->export_to_ixf ) {
                    continue;
                }

                foreach( [ Graph::PROTOCOL_IPV4, Graph::PROTOCOL_IPV6 ] as $protocol ) {
                    $ipv = $protocol === Graph::PROTOCOL_IPV4 ? 4 : 6;

                    if( !$svli->ipvxEnabled( $ipv ) ) {
                        continue;
                    }

                    // Get all destination VLIs on this VLAN for this protocol
                    $dstVlis = VlanInterfaceAggregator::forVlan( $svli->vlan, $ipv )
                        ->filter( function( $dvli ) use ( $svli, $c ) {
                            return $dvli->id !== $svli->id
                                && $dvli->virtualInterface->custid !== $c->id;
                        } );

                    if( $dstVlis->isEmpty() ) {
                        continue;
                    }

                    if( $this->getOutput()->isVeryVerbose() ) {
                        $this->line( "  {$svli->vlan->name} IPv{$ipv}: {$dstVlis->count()} peers" );
                    }

                    // Batch query: 2 API calls for all peers on this VLAN+protocol
                    $peerStats = $akvorado->p2pDailyStats( $svli, $dstVlis, $day, $protocol );

                    // Merge into cumulative stats keyed by peer customer ID
                    $prefix = "ipv{$ipv}_";

                    foreach( $peerStats as $peerId => $data ) {
                        if( !isset( $stats[ $peerId ] ) ) {
                            $stats[ $peerId ] = [
                                'ipv4_total_in'  => 0, 'ipv4_total_out' => 0,
                                'ipv4_max_in'    => 0, 'ipv4_max_out'   => 0,
                                'ipv6_total_in'  => 0, 'ipv6_total_out' => 0,
                                'ipv6_max_in'    => 0, 'ipv6_max_out'   => 0,
                            ];
                        }

                        $stats[ $peerId ][ $prefix . 'total_in' ]  += $data['total_in'];
                        $stats[ $peerId ][ $prefix . 'total_out' ] += $data['total_out'];
                        $stats[ $peerId ][ $prefix . 'max_in' ]     = max(
                            $stats[ $peerId ][ $prefix . 'max_in' ],
                            $data['max_in']
                        );
                        $stats[ $peerId ][ $prefix . 'max_out' ]    = max(
                            $stats[ $peerId ][ $prefix . 'max_out' ],
                            $data['max_out']
                        );
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Store collected stats into the database.
     */
    private function storeStats( array $stats, int $custId, string $day ): void
    {
        DB::transaction( function() use ( $stats, $custId, $day ) {
            foreach( $stats as $peerId => $traffic ) {
                P2pDailyStats::updateOrCreate(
                    [
                        'cust_id' => $custId,
                        'day'     => $day,
                        'peer_id' => $peerId,
                    ],
                    $traffic
                );
            }
        } );
    }
}
