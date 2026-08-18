<?php

declare(strict_types=1);

use App\Enums\TimeBand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable priced version of a tariff (docs/02 FR-007).
        //
        // Rows here are append-only by policy: a price change publishes a new
        // version and closes the previous one with effective_to. Because a
        // charging session stores tariff_version_id, an old session keeps
        // resolving to exactly the rates that applied when it happened, which
        // is what AT-006 requires. Nothing may UPDATE a version that a session
        // already references.
        Schema::create('tariff_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('charging_tariff_id')
                ->constrained('charging_tariffs')->cascadeOnUpdate()->restrictOnDelete();

            // 4dp: unit rates are quoted to more precision than money is
            // rounded to (docs/10 rule 5).
            $table->decimal('energy_rate', 10, 4)->default(0);
            $table->decimal('service_fee', 10, 2)->default(0);
            $table->decimal('parking_fee', 10, 2)->default(0);

            // Nullable, not defaulted: a null VAT rate means "not specified by
            // this tariff", which is different from an explicit 0%. Never
            // assume a national rate in code (docs/10 rule 9).
            $table->decimal('vat_rate', 6, 3)->nullable();

            $table->enum('time_band', array_column(TimeBand::cases(), 'value'))
                ->default(TimeBand::NORMAL->value);

            // Power banding, e.g. a different rate above 100 kW. Null bounds
            // mean unbounded on that side.
            $table->decimal('power_min_kw', 8, 2)->nullable();
            $table->decimal('power_max_kw', 8, 2)->nullable();

            $table->dateTime('effective_from');
            // Null means "still in effect". Overlap validation is enforced in
            // the tariff service (docs/04 -> Admin Tariff: validate overlap),
            // since MySQL cannot express a non-overlap constraint on ranges.
            $table->dateTime('effective_to')->nullable();

            $table->timestamps();

            $table->index(['charging_tariff_id', 'effective_from', 'effective_to'], 'idx_tariff_effective');
            $table->index(['time_band', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_versions');
    }
};
