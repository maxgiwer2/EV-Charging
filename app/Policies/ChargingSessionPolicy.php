<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChargingSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for the central financial record (AT-007, AT-010).
 *
 * Same split as VehiclePolicy: a wrong owner is reported as "not found" so
 * record ids cannot be probed, while an insufficient role is a plain 403.
 */
class ChargingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChargingSession $session): Response
    {
        return $user->canAccessUserData($session->user_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit creating records.');
    }

    /**
     * Editing a confirmed session is a correction, which docs/10 rule 6
     * requires to be auditable -- the audit trail records before/after on every
     * update. Status itself changes only through explicit confirm/cancel
     * actions, never by a field write.
     */
    public function update(User $user, ChargingSession $session): Response
    {
        if (! $user->canAccessUserData($session->user_id)) {
            return Response::denyAsNotFound();
        }

        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit modifying records.');
    }

    public function delete(User $user, ChargingSession $session): Response
    {
        return $this->update($user, $session);
    }

    public function restore(User $user, ChargingSession $session): Response
    {
        return $this->update($user, $session);
    }

    /**
     * Financial records are soft-deleted only (docs/10 rule 15).
     */
    public function forceDelete(User $user, ChargingSession $session): bool
    {
        return false;
    }
}
