<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReceiptStatus;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded receipt file and its review state (docs/02 FR-004, FR-005).
 *
 * file_path is hidden from serialization: docs/07 forbids exposing private
 * file paths, and the API returns a download route instead.
 *
 * @property ReceiptStatus $status
 */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Storage metadata is set by the upload service from the file itself, and
     * status/verification only by explicit transitions -- never from request
     * input, which would let a client declare its own receipt verified.
     *
     * @var list<string>
     */
    protected $fillable = [
        'charging_session_id',
        'receipt_number',
    ];

    /** @var list<string> */
    protected $hidden = [
        'file_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<ChargingSession, $this> */
    public function chargingSession(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class);
    }

    /** @return HasMany<ReceiptOcrResult, $this> */
    public function ocrResults(): HasMany
    {
        return $this->hasMany(ReceiptOcrResult::class);
    }

    /**
     * The most recent OCR attempt. Earlier attempts are retained, never
     * overwritten (docs/05 -> preserve raw OCR).
     *
     * @return HasMany<ReceiptOcrResult, $this>
     */
    public function latestOcrResult(): HasMany
    {
        return $this->ocrResults()->latest('processed_at');
    }

    /** @param Builder<$this> $query */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', ReceiptStatus::OCR_REVIEW);
    }

    /** @param Builder<$this> $query */
    public function scopeUploadedBy(Builder $query, int $userId): void
    {
        $query->where('uploaded_by', $userId);
    }

    public function isVerified(): bool
    {
        return $this->status->isVerified();
    }

    /**
     * Whether $next is a legal successor of the current status. The service
     * layer consults this before writing, so an out-of-order OCR callback
     * cannot resurrect a rejected receipt (docs/05).
     */
    public function canTransitionTo(ReceiptStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }
}
