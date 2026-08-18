<?php

declare(strict_types=1);

use App\Enums\BudgetPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->enum('period', array_column(BudgetPeriod::cases(), 'value'))
                ->default(BudgetPeriod::MONTHLY->value);
            $table->date('period_start');
            $table->date('period_end');

            // Alert thresholds as percentages (docs/02 FR-013 -> 50/80/100,
            // configurable). Stored per budget rather than hard-coded, so a
            // user can opt out of a level or add one (docs/10 rule 9).
            $table->json('alert_thresholds')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
