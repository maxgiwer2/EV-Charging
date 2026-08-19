<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AnomalyDetectionService;
use App\Services\BudgetService;
use Illuminate\Console\Command;

/**
 * Scheduled evaluation of budgets and anomalies (docs/02 FR-013, FR-014).
 *
 * Both are also evaluated when a dashboard is viewed, but a user who does not
 * open the app is exactly the one who most needs to be told they are
 * approaching their budget. An alert that only fires when you go looking is not
 * an alert.
 *
 * Both services are idempotent about notifications, so running daily cannot
 * produce duplicates.
 */
class EvaluateInsightsCommand extends Command
{
    protected $signature = 'insights:evaluate {--user= : Limit to one user id}';

    protected $description = 'Evaluate budgets and detect anomalies, raising any new alerts';

    public function __construct(
        private readonly BudgetService $budgets,
        private readonly AnomalyDetectionService $anomalies,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = User::query()->when(
            $this->option('user') !== null,
            fn ($q) => $q->whereKey((int) $this->option('user')),
        );

        $users = 0;
        $anomalyCount = 0;

        // Chunked: this runs over every account, and loading them all would
        // grow with the user base.
        $query->chunkById(100, function ($chunk) use (&$users, &$anomalyCount): void {
            foreach ($chunk as $user) {
                $users++;

                $this->budgets->evaluateAndNotify($user);
                $anomalyCount += count($this->anomalies->detectAndNotify($user));
            }
        });

        $this->info("Evaluated {$users} user(s); {$anomalyCount} anomaly finding(s).");

        return self::SUCCESS;
    }
}
