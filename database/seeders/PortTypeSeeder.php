<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

use IXP\Models\PortType;

/**
 * EdgeIX ordering: canonical port-type catalogue seeds — the single source
 * of truth for shipped types (the create migration calls this too).
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE: inserts rows whose NAME doesn't exist
 * yet and never touches existing rows — admin edits (patterns added via
 * the UI, thresholds, active flags) are sacred. This replaces the old
 * "rollback + re-migrate" trick, which became unsafe once port_order and
 * switchport overrides started referencing port_type ids (a re-seed
 * assigns fresh ids and silently remaps them).
 *
 * To pull newly shipped types into an existing install:
 *
 *   php artisan db:seed --class=PortTypeSeeder
 *
 * See docs/ordering.md.
 */
class PortTypeSeeder extends Seeder
{
    public const ROWS = [
        [ 'name' => '10GBASE-LR',               'speed' => 10000,  'active' => 1, 'priority' => 10, 'match_patterns' => "10G-?BASE-?LR(?![0-9M])\n10G-?LR(?![0-9M])\n10Gig-?Base-?LR(?![0-9M])", 'notes' => null ],
        [ 'name' => '40GBASE-LR4',              'speed' => 40000,  'active' => 1, 'priority' => 10, 'match_patterns' => "40G-?BASE-?LR4\n40G-?LR4\n^QSFP-?LR4\n40G-?(BASE-?)?P(SM4|LR4)", 'notes' => 'PSM4/PLR4 patterns overlap the 10G breakout row on purpose: the port\'s configured speed (ifHighSpeed 40000 vs 10000) decides which row wins — breakout is port config, not optic.' ],
        [ 'name' => '100GBASE-LR4',             'speed' => 100000, 'active' => 1, 'priority' => 10, 'match_patterns' => "100G-?BASE-?LR4\n100G-?LR4", 'notes' => null ],
        [ 'name' => '100GBASE-LR/FR',           'speed' => 100000, 'active' => 1, 'priority' => 20, 'match_patterns' => "100G-?(BASE-?)?(LR|FR|DR)1?(?![0-9])\n^Q\\.13S1HG\n^TQD015\n^D\\.134HG", 'notes' => 'Single-lambda 100G, sold as 100G LR. Q.13S1HG = third-party 10km LR SKU that is EEPROM-coded as 100GBASE-DR for switch compatibility — trust the P/N, not the optic self-description.' ],
        [ 'name' => '400GBASE-LR4',             'speed' => 400000, 'active' => 1, 'priority' => 10, 'match_patterns' => "400G-?BASE-?LR4\n400G-?LR4\n^TQD015", 'notes' => 'TQD015 (SmartOptics 400G-LR4/MPO) also breaks out as 4x100G LR legs — the 100GBASE-LR/FR row carries the same pattern and port speed decides.' ],
        [ 'name' => '100GBASE-CWDM4',           'speed' => 100000, 'active' => 0, 'priority' => 10, 'match_patterns' => "100G-?(BASE-?)?CWDM4\nQSFP28-CWDM4", 'notes' => 'Core links only (same-rack) — classified for inventory; inactive = never sellable customer stock' ],
        [ 'name' => '400G ZR+ (DCI)',           'speed' => 400000, 'active' => 0, 'priority' => 10, 'match_patterns' => "^TQD013\n400G-?ZR\\+?", 'notes' => 'Tunable coherent 400G ZR+ HighTX (SmartOptics TQD013-TUNC-SO) — DCI/core, never customer stock' ],
        [ 'name' => '400GBASE-FR4',             'speed' => 400000, 'active' => 0, 'priority' => 10, 'match_patterns' => "400G-?(BASE-?)?FR4\nQDD-400G-FR4", 'notes' => 'Core links <2km — classified for inventory only' ],
        [ 'name' => '100G (400G core breakout)','speed' => 100000, 'active' => 0, 'priority' => 15, 'match_patterns' => "^TQD023\n^QSFPDD-4C-FR4", 'notes' => '4x100G logical core links over a 400G optic — TQD023-SL4C-ARI1 via DCP-404; QSFPDD-4C-FR4-4M to 4x100GBASE-FR far ends. Core only, never customer stock' ],
        [ 'name' => '10G (PSM4 breakout)',      'speed' => 10000,  'active' => 1, 'priority' => 15, 'match_patterns' => "40G-?(BASE-?)?PSM4\n40G-?(BASE-?)?PLR4", 'notes' => '4x10G legs of a 40G PSM4/PLR4 in a QSFP cage — each leg is a sellable 10G port' ],
        [ 'name' => '25G (PSM4 breakout)',      'speed' => 25000,  'active' => 0, 'priority' => 15, 'match_patterns' => "100G-?(BASE-?)?PSM4\n100G-?(BASE-?)?PLR4", 'notes' => 'Not sold yet — enable when 25G launches' ],
        [ 'name' => '25GBASE-LR',               'speed' => 25000,  'active' => 0, 'priority' => 20, 'match_patterns' => "25G-?BASE-?LR\n25G-?LR", 'notes' => 'Not sold yet — enable when 25G launches' ],
        [ 'name' => '1GBASE-LX',                'speed' => 1000,   'active' => 0, 'priority' => 20, 'match_patterns' => "1G(BASE)?-?LX\nSFP1G-LX\n1000Base-?LX", 'notes' => 'Legacy 1G — classified for inventory, not sold' ],
        [ 'name' => '10G DWDM (core)',          'speed' => 10000,  'active' => 0, 'priority' => 15, 'match_patterns' => "^AXSD", 'notes' => '10G DWDM 80km optics (AXSD52/56-192-xx) — core/transport, never customer stock' ],
    ];

    public function run(): void
    {
        $added = 0;

        foreach( self::ROWS as $row ) {
            if( PortType::where( 'name', $row['name'] )->exists() ) {
                continue;
            }
            PortType::create( $row + [ 'low_stock_threshold' => null ] );
            $added++;
        }

        if( $this->command ) {
            $this->command->info( "PortTypeSeeder: {$added} new type(s) added, existing rows untouched." );
        }
    }
}
