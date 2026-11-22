<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\LastSuperAdminException;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;

/**
 * Keeps an enabled super admin on the install, for the admin API and the console alike.
 *
 * Deleting, disabling or demoting a user calls this first in the transaction that makes the
 * change. The lock it takes holds until that transaction ends, so two such changes take turns,
 * and the second one finds the super admins as the first one left them. Without the lock, two
 * super admins demoting each other at once would each see the other still enabled and both
 * commit. An install that has no enabled super admin keeps working: the refusal applies only
 * to the one that remains.
 */
final readonly class SuperAdminContinuity
{
    public function __construct(
        private UserRepositoryInterface $users,
    ) {
    }

    /**
     * Refuses a change that leaves the user without enabled super admin status when the stored
     * user is the install's only enabled super admin. Requires an open transaction.
     *
     * @throws LastSuperAdminException when the user is the only enabled super admin
     */
    public function assertAnotherRemains(User $user): void
    {
        $this->users->lockSuperAdminRemovals();

        if ($this->users->soleEnabledSuperAdmin()?->equals($user->getId()) === true) {
            throw LastSuperAdminException::create();
        }
    }
}
