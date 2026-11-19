<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;

/**
 * Tells the previous address of an account that the account's email changed, so an owner
 * whose account was moved to another address finds out.
 *
 * As with the other account emails, a delivery failure must not reach the caller: the change
 * has already taken effect.
 */
interface EmailChangeNoticeInterface
{
    public function deliver(User $user, Email $previousAddress): void;
}
