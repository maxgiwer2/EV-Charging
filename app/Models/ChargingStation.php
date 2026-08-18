<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChargingStationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChargingStation extends Model
{
    /** @use HasFactory<ChargingStationFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'network_id',
        'name',
        'code',
        'address',
        'province',
        'latitude',
        'longitude',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ChargingNetwork, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(ChargingNetwork::class, 'network_id');
    }

    /** @return HasMany<ChargingConnector, $this> */
    public function connectors(): HasMany
    {
        return $this->hasMany(ChargingConnector::class, 'station_id');
    }

    /** @return HasMany<ChargingSession, $this> */
    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class, 'station_id');
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
