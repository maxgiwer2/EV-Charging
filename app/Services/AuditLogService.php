<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the immutable audit trail (docs/02 FR-015, AT-010).
 *
 * Every create/update/delete/verify on a financial record must produce a row
 * here. The service is deliberately the only writer, so redaction cannot be
 * bypassed by a caller assembling its own AuditLog.
 */
class AuditLogService
{
    /**
     * Attribute names never written to the audit trail (docs/10 rule 13).
     *
     * Matching is case-insensitive and by substring, so `password`,
     * `password_confirmation` and `current_password` are all covered by one
     * entry. Receipt file paths are redacted too: docs/07 forbids exposing
     * private storage paths, and an audit row is readable by admins.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'password',
        'token',
        'secret',
        'api_key',
        'authorization',
        'remember_token',
        'file_path',
        'raw_payload',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * Record an action against a model.
     *
     * $before is null for creates, $after is null for deletes.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): AuditLog {
        return AuditLog::create([
            // Falls back to the authenticated user. Stays null for queue jobs
            // and scheduled tasks, which have no actor but must still be
            // recorded.
            'user_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'before_data' => $before === null ? null : $this->redact($before),
            'after_data' => $after === null ? null : $this->redact($after),
            'ip_address' => $this->request->ip(),
            // Column is 500 chars; a crafted header must not throw on insert.
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500),
        ]);
    }

    /**
     * Record a creation, capturing the resulting attributes.
     */
    public function logCreate(Model $entity, ?int $actorId = null): AuditLog
    {
        return $this->log(AuditLog::ACTION_CREATE, $entity, null, $entity->getAttributes(), $actorId);
    }

    /**
     * Record an update, capturing only what actually changed.
     *
     * Storing the full row on both sides would bury the change in noise; the
     * audit question is "what differed", so only dirty keys are kept.
     *
     * @param  array<string, mixed>|null  $originalAttributes  snapshot taken before save()
     */
    public function logUpdate(Model $entity, ?array $originalAttributes = null, ?int $actorId = null): ?AuditLog
    {
        $changes = $entity->getChanges();
        // `updated_at` alone is not a business change worth an audit row.
        unset($changes[$entity->getUpdatedAtColumn() ?? 'updated_at']);

        if ($changes === []) {
            return null;
        }

        // save() syncs the model's "original" attributes to the values just
        // written, so by this point getOriginal() returns the NEW values and
        // would record before === after. Callers therefore snapshot the
        // attributes before saving and pass them in; the fallback only holds
        // when this is called before save().
        $original = $originalAttributes ?? $entity->getOriginal();
        $before = array_intersect_key($original, $changes);

        return $this->log(AuditLog::ACTION_UPDATE, $entity, $before, $changes, $actorId);
    }

    /**
     * Record a deletion. The prior state is captured so a soft-deleted
     * financial record remains explainable (docs/10 rule 15).
     */
    public function logDelete(Model $entity, ?int $actorId = null): AuditLog
    {
        return $this->log(AuditLog::ACTION_DELETE, $entity, $entity->getAttributes(), null, $actorId);
    }

    /**
     * Remove sensitive values, keeping the key so the trail still shows that
     * the attribute changed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::REDACTED_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
