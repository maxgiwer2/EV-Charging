<?php

declare(strict_types=1);

use App\Enums\ChargingMode;
use App\Enums\ChargingType;
use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnUpdate()->restrictOnDelete();

            // Nullable: a home charge has no station, and a public session may
            // be logged before the station exists as a record.
            $table->foreignId('station_id')->nullable()
                ->constrained('charging_stations')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('connector_id')->nullable()
                ->constrained('charging_connectors')->cascadeOnUpdate()->nullOnDelete();

            // The tariff snapshot (AT-006). Points at an immutable version, so
            // the rates that applied at the time stay resolvable forever. The
            // billed breakdown is additionally frozen into charging_cost_lines.
            $table->foreignId('tariff_version_id')->nullable()
                ->constrained('tariff_versions')->cascadeOnUpdate()->restrictOnDelete();

            // Stored UTC; rendered Asia/Bangkok (docs/10 rule 7).
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            // Persisted rather than always derived: a session may be logged
            // with a known duration but no end time.
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->enum('charging_type', array_column(ChargingType::cases(), 'value'));
            $table->enum('charging_mode', array_column(ChargingMode::cases(), 'value'))->nullable();
            $table->decimal('power_kw', 8, 2)->nullable();

            $table->decimal('soc_before', 5, 2)->nullable();
            $table->decimal('soc_after', 5, 2)->nullable();

            $table->decimal('energy_kwh', 10, 3)->nullable();
            // Records how the energy figure was obtained so the cost engine can
            // apply the FR-009 precedence and reports can disclose confidence.
            $table->enum('energy_source', array_column(EnergySource::cases(), 'value'))->nullable();

            // Charger meter readings (docs/02 FR-003).
            $table->decimal('meter_start_kwh', 12, 3)->nullable();
            $table->decimal('meter_end_kwh', 12, 3)->nullable();

            $table->decimal('odometer_before_km', 12, 1)->nullable();
            $table->decimal('odometer_after_km', 12, 1)->nullable();
            $table->decimal('distance_km', 12, 1)->nullable();

            // Money, DECIMAL(12,2) throughout (docs/10 rules 4 and 5).
            // total_amount is the authoritative charged figure; it is not
            // recomputed from the parts on read, because a receipt's own total
            // may legitimately differ by a rounding unit and the receipt wins.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->enum('status', array_column(SessionStatus::cases(), 'value'))
                ->default(SessionStatus::DRAFT->value);

            $table->text('notes')->nullable();

            $table->timestamps();
            // Financial record: destructive operations are soft
            // (docs/10 rule 15).
            $table->softDeletes();

            // Dashboard and report filters (docs/03 -> indexed filters).
            // status leads the analytics index because every total filters on
            // CONFIRMED first (AT-009).
            $table->index(['user_id', 'started_at'], 'idx_session_user_date');
            $table->index(['vehicle_id', 'started_at'], 'idx_session_vehicle_date');
            $table->index(['user_id', 'status', 'started_at'], 'idx_session_user_status_date');
            $table->index(['station_id', 'started_at']);
            $table->index(['charging_type', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_sessions');
    }
};
