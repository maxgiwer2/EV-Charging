<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * RBAC roles (docs/02 FR-001, database/schema.sql users.role).
 *
 * Capability checks live here rather than in policies so the rules are stated
 * once. Policies decide *which record* a user may touch; this decides *what
 * kind of action* the role permits at all.
 */
enum UserRole: string
{
    case ADMIN = 'admin';
    case USER = 'user';
    case VIEWER = 'viewer';

    /**
     * May create, update or delete their own records.
     *
     * Viewers are read-only by definition, so every write path must check this
     * before a policy's ownership test (AT-007).
     */
    public function canWrite(): bool
    {
        return $this !== self::VIEWER;
    }

    /**
     * May manage shared reference data: networks, stations, connectors and
     * tariffs. These are global records, so ownership cannot gate them --
     * only the role can (docs/04 -> Admin Tariff flow).
     */
    public function canManageReferenceData(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * May read records belonging to other users. Reserved for admins; a normal
     * user is confined to their own data (AT-007).
     */
    public function canAccessAllUsers(): bool
    {
        return $this === self::ADMIN;
    }

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::USER => 'User',
            self::VIEWER => 'Viewer',
        };
    }
}
