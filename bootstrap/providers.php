<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ProductionServiceProvider;

return [
    AppServiceProvider::class,
    // Refuses to boot on an unsafe production configuration, and forces https
    // URL generation. No-op in every other environment.
    ProductionServiceProvider::class,
];
