<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            $table->string('make', 100);
            $table->string('model', 100);
            $table->string('trim', 100)->nullable();
            $table->smallInteger('model_year')->nullable();
            $table->string('plate_no', 30)->nullable();
            $table->string('vin', 100)->nullable();

            // Usable battery capacity. Required to turn a SOC delta into kWh
            // when no metered reading exists (docs/02 FR-009 -> SOC estimate).
            $table->decimal('battery_kwh', 8, 3)->nullable();
            $table->decimal('ac_max_kw', 8, 2)->nullable();
            $table->decimal('dc_max_kw', 8, 2)->nullable();

            $table->decimal('initial_odometer_km', 12, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Not itself a financial record, but charging sessions reference
            // it, so removal is soft to keep historical reports readable.
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            // Unique within this system's records. MySQL allows repeated
            // NULLs, so a vehicle without a recorded VIN is still valid.
            $table->unique('vin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
