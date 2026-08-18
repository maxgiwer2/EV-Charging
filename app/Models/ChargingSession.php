<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChargingMode;
use App\Enums\ChargingType;
use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use Database\Factories\ChargingSessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single charge (docs/02 FR-003). The system's central financial record.
 *
 * Derived metrics are intentionally NOT computed here as accessors -- they are
 * the cost engine's job (M3) and must refuse to divide by zero
 * (docs/06 -> do not calculate when denominator is zero/null).
 *
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property SessionStatus $status
 * @property ChargingType $charging_type
 * @property ChargingMode|null $charging_mode
 * @property EnergySource|null $energy_source
 */
class ChargingSession extends Model
{
    /** @use HasFactory<ChargingSessionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Money and status columns are absent: totals are written by the cost
     * engine and status by an explicit transition, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vehicle_id',
        'station_id',
        'connector_id',
        'started_at',
        'ended_at',
        'duration_minutes',
        'charging_type',
        'charging_mode',
        'power_kw',
        'soc_before',
        'soc_after',
        'energy_kwh',
        'energy_source',
        'meter_start_kwh',
        'meter_end_kwh',
        'odometer_before_km',
        'odometer_after_km',
        'distance_km',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
            'charging_type' => ChargingType::class,
            'charging_mode' => ChargingMode::class,
            'status' => SessionStatus::class,
            'energy_source' => EnergySource::class,
            'power_kw' => 'decimal:2',
            'soc_before' => 'decimal:2',
            'soc_after' => 'decimal:2',
            'energy_kwh' => 'decimal:3',
            'meter_start_kwh' => 'decimal:3',
            'meter_end_kwh' => 'decimal:3',
            'odometer_before_km' => 'decimal:1',
            'odometer_after_km' => 'decimal:1',
            'distance_km' => 'decimal:1',
            // Money as fixed-precision strings, never floats (docs/10 rule 4).
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'station_id');
    }

    /** @return BelongsTo<ChargingConnector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(ChargingConnector::class, 'connector_id');
    }

    /** @return BelongsTo<TariffVersion, $this> */
    public function tariffVersion(): BelongsTo
    {
        return $this->belongsTo(TariffVersion::class, 'tariff_version_id');
    }

    /** @return HasMany<ChargingCostLine, $this> */
    public function costLines(): HasMany
    {
        return $this->hasMany(ChargingCostLine::class);
    }

    /** @return HasMany<Receipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Sessions that count as financial fact.
     *
     * Every dashboard, report and export must apply this, otherwise drafts and
     * cancellations inflate the totals and AT-009 reconciliation fails.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', SessionStatus::CONFIRMED);
    }

    /** @param Builder<$this> $query */
    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    /**
     * Half-open range [$from, $to): avoids double-counting a session that sits
     * exactly on a month boundary when adjacent periods are compared.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStartedBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->where('started_at', '>=', $from)->where('started_at', '<', $to);
    }

    // ---------------------------------------------------------------------
    // Domain helpers
    // ---------------------------------------------------------------------

    public function isConfirmed(): bool
    {
        return $this->status === SessionStatus::CONFIRMED;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Distance covered since the previous charge, if both odometer readings
     * are present. Returns null rather than 0 when unknown, so efficiency
     * metrics can skip the session instead of dividing by a fabricated zero.
     */
    public function resolvedDistanceKm(): ?string
    {
        if ($this->distance_km !== null) {
            return (string) $this->distance_km;
        }

        if ($this->odometer_before_km === null || $this->odometer_after_km === null) {
            return null;
        }

        return bcsub((string) $this->odometer_after_km, (string) $this->odometer_before_km, 1);
    }
}
