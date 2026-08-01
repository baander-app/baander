<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\User;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface as SymfonyPasswordHasherInterface;

final class PasswordHasher implements PasswordHasherInterface
{
    private readonly SymfonyPasswordHasherInterface $hasher;

    public function __construct(PasswordHasherFactoryInterface $hasherFactory)
    {
        $this->hasher = $hasherFactory->getPasswordHasher(SecurityUser::class);
    }

    public function hash(string $plainPassword): string
    {
        return $this->hasher->hash($plainPassword);
    }

    public function verify(string $plainPassword, string $hashedPassword): bool
    {
        return $this->hasher->verify($hashedPassword, $plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return $this->hasher->needsRehash($hashedPassword);
    }
}
