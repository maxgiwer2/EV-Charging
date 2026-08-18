<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A budget is personal financial planning (docs/02 FR-013).
 *
 * Same split as everywhere else: a wrong owner is reported as not found so ids
 * cannot be probed, an insufficient role is a plain 403 (AT-007).
 */
class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Budget $budget): Response
    {
        return $user->canAccessUserData($budget->user_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit setting a budget.');
    }

    public function update(User $user, Budget $budget): Response
    {
        if (! $user->canAccessUserData($budget->user_id)) {
            return Response::denyAsNotFound();
        }

        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit changing a budget.');
    }

    public function delete(User $user, Budget $budget): Response
    {
        return $this->update($user, $budget);
    }
}
