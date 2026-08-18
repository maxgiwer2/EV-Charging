<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChargingNetwork;
use App\Models\User;

/**
 * Shared reference data (docs/02 FR-006).
 *
 * No user owns a network, so ownership cannot gate access. Everyone
 * authenticated may read -- users must be able to attribute a session to a
 * network -- and only admins may write.
 */
class ChargingNetworkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChargingNetwork $network): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageReferenceData();
    }

    public function update(User $user, ChargingNetwork $network): bool
    {
        return $user->canManageReferenceData();
    }

    public function delete(User $user, ChargingNetwork $network): bool
    {
        return $user->canManageReferenceData();
    }

    public function restore(User $user, ChargingNetwork $network): bool
    {
        return $user->canManageReferenceData();
    }

    /**
     * Historical sessions reference networks through their stations; a hard
     * delete would break that history.
     */
    public function forceDelete(User $user, ChargingNetwork $network): bool
    {
        return false;
    }
}
