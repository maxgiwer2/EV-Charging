<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChargingType;
use Database\Factories\ChargingTariffFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The stable identity of a pricing scheme. Rates live in versions
 * (docs/02 FR-007).
 *
 * @property ChargingType $charging_type
 */
class ChargingTariff extends Model
{
    /** @use HasFactory<ChargingTariffFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'network_id',
        'station_id',
        'name',
        'charging_type',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'charging_type' => ChargingType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ChargingNetwork, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(ChargingNetwork::class, 'network_id');
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'station_id');
    }

    /** @return HasMany<TariffVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TariffVersion::class, 'charging_tariff_id');
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
