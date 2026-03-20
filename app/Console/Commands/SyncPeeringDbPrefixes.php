<?php

namespace IXP\Console\Commands;

/*
 * Copyright (C) 2009 - 2024 Internet Neutral Exchange Association Company Limited By Guarantee.
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

use IXP\Models\Customer;
use IXP\Services\PeeringDb;

/**
 * Artisan command to sync PeeringDB prefix limits into IXP-Manager.
 *
 * Fetches info_prefixes4 / info_prefixes6 from PeeringDB in a single API call and
 * updates the global maxprefixes / maxprefixesv6 fields on each customer whose ASN
 * is registered in PeeringDB.
 *
 * Per-VlanInterface limits (ipv4maxbgpprefix / ipv6maxbgpprefix) are NOT touched —
 * those are admin-managed overrides.
 */
class SyncPeeringDbPrefixes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ixp-manager:sync-peeringdb-prefixes
                            {--dry-run        : Show what would change without saving}
                            {--no-override    : Skip customers that already have non-zero prefix limits set}
                            {--multiplier=1.0 : Multiply PeeringDB values by this factor (e.g. 1.2 for a 20% buffer)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Sync PeeringDB prefix limits (info_prefixes4/6) into customer maxprefixes/maxprefixesv6";

    /**
     * Execute the console command.
     *
     * @throws
     *
     * @psalm-return 0|1|2
     */
    public function handle(): int
    {
        $dryRun     = (bool)$this->option( 'dry-run' );
        $noOverride = (bool)$this->option( 'no-override' );
        $multiplier = (float)$this->option( 'multiplier' );

        if( $multiplier <= 0 ) {
            $this->error( "Multiplier must be > 0." );
            return 1;
        }

        $pdb = app()->make( PeeringDb::class );

        foreach( $pdb->warnOnBadAuthMethods() as $w ) {
            $this->warn( $w );
        }

        if( $dryRun ) {
            $this->warn( "DRY RUN — no changes will be saved." );
        }

        $this->info( "Fetching prefix limits from PeeringDB..." );

        $limits = $pdb->getAllNetworkPrefixLimits();

        if( $limits === false ) {
            $this->error( "PeeringDB API error: " . $pdb->error );
            return 2;
        }

        $this->info( sprintf( "Retrieved prefix limits for %d networks. Processing customers...", count( $limits ) ) );

        $updated  = 0;
        $skipped  = 0;
        $notFound = 0;

        foreach( Customer::trafficking()->current()->where( 'in_peeringdb', true )->get() as $c ) {
            $asn = (int)$c->autsys;

            if( !isset( $limits[ $asn ] ) ) {
                $this->line( "  <comment>NOT FOUND</comment>  AS{$asn} {$c->name} — not in PeeringDB prefix data" );
                $notFound++;
                continue;
            }

            $pdbV4 = $limits[ $asn ]['info_prefixes4'];
            $pdbV6 = $limits[ $asn ]['info_prefixes6'];

            // Apply multiplier and round up
            $newV4 = $pdbV4 !== null ? (int)ceil( $pdbV4 * $multiplier ) : null;
            $newV6 = $pdbV6 !== null ? (int)ceil( $pdbV6 * $multiplier ) : null;

            // Skip if neither PeeringDB value is set
            if( $newV4 === null && $newV6 === null ) {
                $this->line( "  <comment>NO DATA </comment>  AS{$asn} {$c->name} — PeeringDB has no prefix limits" );
                $skipped++;
                continue;
            }

            // --no-override: skip if both are already set
            if( $noOverride && $c->maxprefixes > 0 && $c->maxprefixesv6 > 0 ) {
                $this->line( "  <comment>SKIP    </comment>  AS{$asn} {$c->name} — already has limits set (IPv4: {$c->maxprefixes}, IPv6: {$c->maxprefixesv6})" );
                $skipped++;
                continue;
            }

            $changes = [];

            if( $newV4 !== null && ( !$noOverride || !$c->maxprefixes ) ) {
                if( $c->maxprefixes !== $newV4 ) {
                    $changes['maxprefixes'] = [ 'from' => $c->maxprefixes, 'to' => $newV4 ];
                }
            }

            if( $newV6 !== null && ( !$noOverride || !$c->maxprefixesv6 ) ) {
                if( $c->maxprefixesv6 !== $newV6 ) {
                    $changes['maxprefixesv6'] = [ 'from' => $c->maxprefixesv6, 'to' => $newV6 ];
                }
            }

            if( empty( $changes ) ) {
                $this->line( "  <info>NO CHANGE</info> AS{$asn} {$c->name} — already up to date (IPv4: {$c->maxprefixes}, IPv6: {$c->maxprefixesv6})" );
                continue;
            }

            $summary = [];
            foreach( $changes as $field => $vals ) {
                $summary[] = "{$field}: {$vals['from']} → {$vals['to']}";
            }

            $this->line( "  <info>UPDATE  </info>  AS{$asn} {$c->name} — " . implode( ', ', $summary ) );

            if( !$dryRun ) {
                foreach( $changes as $field => $vals ) {
                    $c->$field = $vals['to'];
                }
                $c->save();
            }

            $updated++;
        }

        $this->newLine();
        $this->info( "Done. Updated: {$updated} | Skipped/no-data: {$skipped} | Not in PeeringDB prefix data: {$notFound}" );

        if( $dryRun ) {
            $this->warn( "DRY RUN — no changes were saved." );
        }

        return 0;
    }
}
