<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChargingTariff;
use App\Models\User;

/**
 * Tariffs are shared reference data (docs/04 -> Admin Tariff).
 *
 * Everyone authenticated may read them -- a user is entitled to see the rate
 * they were charged -- and only admins may publish or change them.
 */
class ChargingTariffPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChargingTariff $tariff): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageReferenceData();
    }

    public function update(User $user, ChargingTariff $tariff): bool
    {
        return $user->canManageReferenceData();
    }

    public function delete(User $user, ChargingTariff $tariff): bool
    {
        return $user->canManageReferenceData();
    }

    /**
     * Never: historical sessions resolve their price through this tariff's
     * versions (AT-006).
     */
    public function forceDelete(User $user, ChargingTariff $tariff): bool
    {
        return false;
    }
}
