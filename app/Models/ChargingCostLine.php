<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChargingCostLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a session's charged total (energy, fees, discount, VAT).
 *
 * Together these freeze the breakdown that produced total_amount, so a
 * historical session stays explainable after tariffs change (AT-006).
 */
class ChargingCostLine extends Model
{
    /** @use HasFactory<ChargingCostLineFactory> */
    use HasFactory;

    public const TYPE_ENERGY = 'ENERGY';

    public const TYPE_SERVICE_FEE = 'SERVICE_FEE';

    public const TYPE_PARKING_FEE = 'PARKING_FEE';

    public const TYPE_DISCOUNT = 'DISCOUNT';

    public const TYPE_VAT = 'VAT';

    /** @var list<string> */
    protected $fillable = [
        'charging_session_id',
        'line_type',
        'quantity',
        'unit_price',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ChargingSession, $this> */
    public function chargingSession(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class);
    }
}
