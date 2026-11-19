<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Email;

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
        /** The address the token verifies, and the one the link goes to. */
        public Email $address,
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
