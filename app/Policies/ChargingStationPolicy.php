<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChargingStation;
use App\Models\User;

/**
 * Shared reference data: read by all, written by admins (docs/02 FR-006).
 */
class ChargingStationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChargingStation $station): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageReferenceData();
    }

    public function update(User $user, ChargingStation $station): bool
    {
        return $user->canManageReferenceData();
    }

    public function delete(User $user, ChargingStation $station): bool
    {
        return $user->canManageReferenceData();
    }

    public function restore(User $user, ChargingStation $station): bool
    {
        return $user->canManageReferenceData();
    }

    public function forceDelete(User $user, ChargingStation $station): bool
    {
        return false;
    }
}
