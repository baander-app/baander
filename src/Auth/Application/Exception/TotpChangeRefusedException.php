<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** A TOTP setup, enable or disable request was refused for a reason the user can act on. */
final class TotpChangeRefusedException extends RuntimeException
{
    public function __construct(public readonly TotpChangeRefusal $refusal, ?\Throwable $previous = null)
    {
        parent::__construct('TOTP change refused: ' . $refusal->value . '.', 0, $previous);
    }
}
