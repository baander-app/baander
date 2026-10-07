<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * A newly issued email verification token, held between storing its hash and handing it to
 * the delivery. It carries a credential, so it is never serialized.
 */
final readonly class IssuedEmailVerificationToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An email verification token is a credential and must not be serialized.');
    }
}
