<?php

declare(strict_types=1);

use App\Enums\ChargingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A tariff is the stable identity ("AC public rate at network X").
        // Prices live in tariff_versions, never here, so that changing a rate
        // creates a new version instead of rewriting history
        // (docs/02 FR-007, AT-006).
        //
        // Note: database/erd.md previously showed effective_from/effective_to
        // on this table. That was inconsistent with database/schema.sql and
        // with versioning; the effective period belongs to the version. The
        // ERD has been corrected.
        Schema::create('charging_tariffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_id')->nullable()
                ->constrained('charging_networks')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('station_id')->nullable()
                ->constrained('charging_stations')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('name', 200);
            $table->enum('charging_type', array_column(ChargingType::cases(), 'value'));
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Resolving the tariff for a session filters on scope + type.
            $table->index(['network_id', 'charging_type']);
            $table->index(['station_id', 'charging_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_tariffs');
    }
};
