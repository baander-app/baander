<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface PasswordResetTokenRepositoryInterface
{
    /**
     * Persist a password reset token for the given email.
     * If a token already exists for this email, it will be updated.
     */
    public function save(string $email, string $token): void;

    /**
     * Find the current password reset token for the given email.
     *
     * @return string|null The token string, or null if none exists
     */
    public function findByEmail(string $email): ?string;
}
