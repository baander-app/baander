<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * A signed-in user opened a verification link that is still valid but was sent for another
 * account. Nothing is redeemed, so the link still works once that account's owner opens it while
 * signed in to it.
 */
final class VerificationLinkOtherAccountException extends RuntimeException
{
    public const string REASON = 'verification_link_other_account';

    public function __construct()
    {
        parent::__construct('The verification link was sent for another account.');
    }
}
