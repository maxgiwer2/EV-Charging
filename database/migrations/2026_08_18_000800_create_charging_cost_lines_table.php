<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The frozen cost breakdown for a session: one row per charged
        // component (energy, service fee, parking, discount, VAT).
        //
        // This is what makes a historical total reproducible even if the
        // tariff is later restructured (AT-006). Lines are written inside the
        // same transaction as their session (docs/10 rule 8) and are never
        // edited in place -- a correction supersedes the whole set.
        Schema::create('charging_cost_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('charging_session_id')
                ->constrained('charging_sessions')->cascadeOnUpdate()->cascadeOnDelete();

            // e.g. ENERGY, SERVICE_FEE, PARKING_FEE, DISCOUNT, VAT. Kept as a
            // string rather than an enum: new charge components appear on real
            // receipts and must not require a migration to record.
            $table->string('line_type', 50);

            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('unit_price', 12, 4)->nullable();
            // Signed: discounts are stored negative so the lines sum to the
            // session subtotal without special-casing by type.
            $table->decimal('amount', 12, 2);

            $table->timestamps();

            $table->index(['charging_session_id', 'line_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_cost_lines');
    }
};
