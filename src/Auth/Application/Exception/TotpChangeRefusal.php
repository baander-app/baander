<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

/** Why a TOTP setup, enable or disable request was refused; each has its own translated message. */
enum TotpChangeRefusal: string
{
    case AlreadyEnabled = 'totp_already_enabled';
    case SetupMissing = 'totp_setup_missing';
    case InvalidCode = 'totp_code_invalid';
    case NotEnabled = 'totp_not_enabled';

    /** The message key in the auth translation domain. */
    public function translationKey(): string
    {
        return 'errors.' . $this->value;
    }
}
