<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
        ]);

        // Development accounts only. These run in local/testing; production
        // provisioning creates the first admin out of band so no known
        // password ever exists on a live system.
        if (! app()->environment('production')) {
            $this->call([
                DemoUserSeeder::class,
            ]);
        }
    }
}
