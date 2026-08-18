<?php

declare(strict_types=1);

use App\Enums\ChargingMode;
use App\Enums\ConnectorStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_connectors', function (Blueprint $table): void {
            $table->id();
            // A connector has no meaning without its station, so it is removed
            // with the station. Sessions reference the connector nullably, so
            // history survives (see charging_sessions.connector_id).
            $table->foreignId('station_id')
                ->constrained('charging_stations')->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('connector_type', 50);
            $table->enum('charging_mode', array_column(ChargingMode::cases(), 'value'));
            $table->decimal('max_power_kw', 8, 2)->nullable();
            $table->enum('status', array_column(ConnectorStatus::cases(), 'value'))
                ->default(ConnectorStatus::UNKNOWN->value);
            $table->timestamps();

            $table->index(['station_id', 'charging_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_connectors');
    }
};
