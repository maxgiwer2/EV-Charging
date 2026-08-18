<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\Response;

/**
 * Ownership + role authorization for vehicles (AT-007).
 *
 * The two failure modes are deliberately distinguished:
 *
 * - Wrong owner -> denyAsNotFound(). Returning 403 would confirm that the id
 *   exists, letting an attacker enumerate other users' records.
 * - Right owner, insufficient role (a viewer) -> deny(). The record is already
 *   known to the caller, so 403 leaks nothing and is the honest answer.
 *
 * Ownership is therefore always checked before the role.
 */
class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        // The listing query is scoped to the caller, so this leaks nothing.
        return true;
    }

    public function view(User $user, Vehicle $vehicle): Response
    {
        return $user->canAccessUserData($vehicle->user_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit creating records.');
    }

    public function update(User $user, Vehicle $vehicle): Response
    {
        if (! $user->canAccessUserData($vehicle->user_id)) {
            return Response::denyAsNotFound();
        }

        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit modifying records.');
    }

    public function delete(User $user, Vehicle $vehicle): Response
    {
        return $this->update($user, $vehicle);
    }

    public function restore(User $user, Vehicle $vehicle): Response
    {
        return $this->update($user, $vehicle);
    }

    /**
     * Never permitted: vehicles are referenced by historical sessions, so a
     * hard delete would orphan financial history.
     */
    public function forceDelete(User $user, Vehicle $vehicle): bool
    {
        return false;
    }
}
