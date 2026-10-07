<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

/**
 * A password reset email waiting to be sent. Held in memory only, never serialized.
 */
final readonly class PasswordResetEmail
{
    public function __construct(
        public string $userId,
        public string $address,
        public string $name,
        public string $link,
        public int $validMinutes,
        public string $locale,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A password reset email carries a credential and must not be serialized.');
    }
}
