<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * A stored TOTP secret does not open under the configured key, as after a change of
 * AUTH_TOTP_ENCRYPTION_KEY. TOTP stays on for the account: it cannot sign in with a
 * password until an operator resets its TOTP.
 */
final class TotpSecretUnavailableException extends RuntimeException
{
    public static function forStoredSecret(): self
    {
        return new self('The stored TOTP secret cannot be decrypted with the configured key.');
    }
}
