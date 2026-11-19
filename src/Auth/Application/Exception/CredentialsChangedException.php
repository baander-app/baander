<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * The user's password changed after the credential that authorizes this issuance was proved:
 * the login, the approval behind a code, or the refresh chain. Nothing is issued.
 */
final class CredentialsChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The account\'s credentials changed after this authorization was granted.');
    }
}
