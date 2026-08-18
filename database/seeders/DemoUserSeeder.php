<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development accounts. Never seeded in production (see DatabaseSeeder).
 *
 * One account per role so authorization behaviour (AT-007) can be exercised by
 * hand as well as by the test suite.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ],
        );

        $user = User::firstOrCreate(
            ['email' => 'user@example.test'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'role' => UserRole::USER,
                'email_verified_at' => now(),
            ],
        );

        User::firstOrCreate(
            ['email' => 'viewer@example.test'],
            [
                'name' => 'Demo Viewer',
                'password' => Hash::make('password'),
                'role' => UserRole::VIEWER,
                'email_verified_at' => now(),
            ],
        );

        if ($user->vehicles()->count() === 0) {
            Vehicle::create([
                'user_id' => $user->id,
                'make' => 'BYD',
                'model' => 'Atto 3',
                'model_year' => 2024,
                'battery_kwh' => 60.480,
                'ac_max_kw' => 7.00,
                'dc_max_kw' => 88.00,
                'initial_odometer_km' => 0,
                'is_active' => true,
            ]);
        }

        $this->command->info('Demo accounts: admin@example.test / user@example.test / viewer@example.test (password: "password")');
    }
}
