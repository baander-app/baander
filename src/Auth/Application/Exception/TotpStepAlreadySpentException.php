<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** The TOTP code's time step was already spent by another request; the code counts once. */
final class TotpStepAlreadySpentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The TOTP code was already used.');
    }
}
