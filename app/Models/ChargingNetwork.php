<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChargingNetworkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A charging operator (docs/02 FR-006). Shared reference data -- not owned by
 * any user, so authorization is by role (admin writes, everyone reads).
 */
class ChargingNetwork extends Model
{
    /** @use HasFactory<ChargingNetworkFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'code',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ChargingStation, $this> */
    public function stations(): HasMany
    {
        return $this->hasMany(ChargingStation::class, 'network_id');
    }

    /** @return HasMany<ChargingTariff, $this> */
    public function tariffs(): HasMany
    {
        return $this->hasMany(ChargingTariff::class, 'network_id');
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
