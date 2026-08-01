<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hashedPassword): bool;

    public function needsRehash(string $hashedPassword): bool;
}
