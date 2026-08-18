<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TimeBand;
use Carbon\CarbonInterface;
use Database\Factories\TariffVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable priced version of a tariff.
 *
 * Rows referenced by a charging session must never be updated: the session's
 * reproducibility depends on these values staying frozen (AT-006). Corrections
 * publish a new version instead.
 *
 * @property TimeBand $time_band
 */
class TariffVersion extends Model
{
    /** @use HasFactory<TariffVersionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'charging_tariff_id',
        'energy_rate',
        'service_fee',
        'parking_fee',
        'vat_rate',
        'time_band',
        'power_min_kw',
        'power_max_kw',
        'effective_from',
        'effective_to',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'time_band' => TimeBand::class,
            // Rates carry more precision than money; money is rounded once, at
            // the end of a calculation (docs/10 rules 4 and 5).
            'energy_rate' => 'decimal:4',
            'service_fee' => 'decimal:2',
            'parking_fee' => 'decimal:2',
            'vat_rate' => 'decimal:3',
            'power_min_kw' => 'decimal:2',
            'power_max_kw' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChargingTariff, $this> */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(ChargingTariff::class, 'charging_tariff_id');
    }

    /** @return HasMany<ChargingSession, $this> */
    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class, 'tariff_version_id');
    }

    /**
     * Whether this version applies at $moment. A null effective_to means the
     * version is still open-ended.
     */
    public function isEffectiveAt(CarbonInterface $moment): bool
    {
        if ($moment->lt($this->effective_from)) {
            return false;
        }

        return $this->effective_to === null || $moment->lt($this->effective_to);
    }

    /**
     * Versions in effect at $moment, newest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEffectiveAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('effective_from', '<=', $moment)
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $moment);
            })
            ->orderByDesc('effective_from');
    }

    /**
     * Whether this version has been used to price a session. Such a version is
     * frozen -- editing it would silently rewrite historical totals.
     */
    public function isReferencedBySession(): bool
    {
        return $this->chargingSessions()->withTrashed()->exists();
    }
}
