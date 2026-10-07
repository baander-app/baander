<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** An operator sets a user's password, from the admin panel or the CLI. */
final readonly class SetUserPasswordCommand
{
    public function __construct(
        /** The user's email address or UUID. */
        public string $identifier,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
