<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

/** A registration arrived while self-registration is closed; only an operator can create the account. */
final class RegistrationClosedException extends \RuntimeException
{
    public const string REASON = 'registration_closed';

    public function __construct()
    {
        parent::__construct('Self-registration is closed.');
    }
}
