<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

/**
 * Whether anyone may create an account through the public registration endpoint. Operators
 * open and close it at runtime; every call reads the current state.
 */
interface SelfRegistrationInterface
{
    public function isOpen(): bool;
}
