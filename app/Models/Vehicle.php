<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'make',
        'model',
        'trim',
        'model_year',
        'plate_no',
        'vin',
        'battery_kwh',
        'ac_max_kw',
        'dc_max_kw',
        'initial_odometer_km',
        'is_active',
    ];

    /**
     * Decimal columns are cast to fixed-precision strings rather than floats.
     * battery_kwh feeds SOC-based energy estimates (FR-009), and a float there
     * would introduce drift into a figure that ends up costing money.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'battery_kwh' => 'decimal:3',
            'ac_max_kw' => 'decimal:2',
            'dc_max_kw' => 'decimal:2',
            'initial_odometer_km' => 'decimal:1',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ChargingSession, $this> */
    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    public function displayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->make,
            $this->model,
            $this->trim,
        ])));
    }
}
