<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * A new password that passed the password policy, already hashed. PasswordChanger::hash()
 * makes it before any transaction opens, since hashing is deliberately slow.
 */
final readonly class HashedPassword
{
    public function __construct(
        public string $hash,
    ) {
    }
}
