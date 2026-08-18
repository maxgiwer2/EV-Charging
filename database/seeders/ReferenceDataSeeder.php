<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ChargingMode;
use App\Enums\ConnectorStatus;
use App\Models\ChargingNetwork;
use App\Models\ChargingStation;
use Illuminate\Database\Seeder;

/**
 * Charging networks operating in Thailand.
 *
 * This is reference data, not business rules: no rate, fee or tax value is
 * seeded here (docs/10 rule 9). Tariffs are created by an admin through the
 * versioned tariff flow so that every price carries an effective period and an
 * audit trail (docs/04 -> Admin Tariff).
 *
 * Idempotent: safe to re-run without duplicating rows.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $networks = [
            ['code' => 'PEA_VOLTA', 'name' => 'PEA VOLTA'],
            ['code' => 'EA_ANYWHERE', 'name' => 'EA Anywhere'],
            ['code' => 'PTT_ONION', 'name' => 'on-ion (PTT)'],
            ['code' => 'MEA_EV', 'name' => 'MEA EV'],
            ['code' => 'ELEXA', 'name' => 'Elexa'],
            ['code' => 'SHARGE', 'name' => 'SHARGE'],
        ];

        foreach ($networks as $network) {
            ChargingNetwork::firstOrCreate(
                ['code' => $network['code']],
                ['name' => $network['name'], 'is_active' => true],
            );
        }

        // One example station with connectors, so a fresh install has
        // something selectable in the session form.
        $volta = ChargingNetwork::where('code', 'PEA_VOLTA')->first();

        if ($volta !== null) {
            $station = ChargingStation::firstOrCreate(
                ['code' => 'VOLTA-DEMO-01'],
                [
                    'network_id' => $volta->id,
                    'name' => 'Demo Station (Bangkok)',
                    'province' => 'Bangkok',
                    'is_active' => true,
                ],
            );

            if ($station->connectors()->count() === 0) {
                $station->connectors()->createMany([
                    [
                        'connector_type' => 'CCS2',
                        'charging_mode' => ChargingMode::DC,
                        'max_power_kw' => 120,
                        'status' => ConnectorStatus::AVAILABLE,
                    ],
                    [
                        'connector_type' => 'Type 2',
                        'charging_mode' => ChargingMode::AC,
                        'max_power_kw' => 22,
                        'status' => ConnectorStatus::AVAILABLE,
                    ],
                ]);
            }
        }
    }
}
