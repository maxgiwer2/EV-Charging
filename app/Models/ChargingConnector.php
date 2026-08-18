<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChargingMode;
use App\Enums\ConnectorStatus;
use Database\Factories\ChargingConnectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ChargingMode $charging_mode
 * @property ConnectorStatus $status
 */
class ChargingConnector extends Model
{
    /** @use HasFactory<ChargingConnectorFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'station_id',
        'connector_type',
        'charging_mode',
        'max_power_kw',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'charging_mode' => ChargingMode::class,
            'status' => ConnectorStatus::class,
            'max_power_kw' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'station_id');
    }
}
