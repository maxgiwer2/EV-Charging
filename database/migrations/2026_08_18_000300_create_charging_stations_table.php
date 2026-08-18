<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_id')->nullable()
                ->constrained('charging_networks')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('name', 200);
            $table->string('code', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('province', 100)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('network_id');
            // Station and province filters back the ranking reports
            // (docs/06 -> Dimensions, station ranking).
            $table->index(['province', 'is_active']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_stations');
    }
};
