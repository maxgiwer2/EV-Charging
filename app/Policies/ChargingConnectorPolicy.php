<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChargingConnector;
use App\Models\User;

/**
 * Shared reference data: read by all, written by admins (docs/02 FR-006).
 */
class ChargingConnectorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChargingConnector $connector): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageReferenceData();
    }

    public function update(User $user, ChargingConnector $connector): bool
    {
        return $user->canManageReferenceData();
    }

    public function delete(User $user, ChargingConnector $connector): bool
    {
        return $user->canManageReferenceData();
    }
}
