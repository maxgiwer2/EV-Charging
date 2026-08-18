<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Receipts are private financial documents (docs/03, AT-007).
 *
 * The download route depends on `view` in particular: it is the only thing
 * standing between an authenticated user and someone else's receipt image,
 * since the file itself is on a disk with no public URL.
 *
 * As elsewhere, a wrong owner is reported as "not found" so ids cannot be
 * probed, while an insufficient role is a plain 403.
 */
class ReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Receipt $receipt): Response
    {
        return $user->canAccessUserData($receipt->uploaded_by)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit uploading receipts.');
    }

    /**
     * Confirming a receipt turns extracted text into financial fact
     * (AT-004), so it needs the same ownership and role checks as any write.
     */
    public function verify(User $user, Receipt $receipt): Response
    {
        if (! $user->canAccessUserData($receipt->uploaded_by)) {
            return Response::denyAsNotFound();
        }

        return $user->canWrite()
            ? Response::allow()
            : Response::deny('Your role does not permit verifying receipts.');
    }

    public function reject(User $user, Receipt $receipt): Response
    {
        return $this->verify($user, $receipt);
    }

    public function update(User $user, Receipt $receipt): Response
    {
        return $this->verify($user, $receipt);
    }

    public function delete(User $user, Receipt $receipt): Response
    {
        return $this->verify($user, $receipt);
    }

    /**
     * Never permitted: a receipt is the evidence behind a financial record
     * (docs/10 rule 15).
     */
    public function forceDelete(User $user, Receipt $receipt): bool
    {
        return false;
    }
}
