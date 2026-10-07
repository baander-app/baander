<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\IssuedEmailVerificationToken;
use App\Auth\Application\Port\EmailVerificationDeliveryInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;

/**
 * Issues email verification tokens and hands them to the delivery.
 *
 * Registration, an email change and a resend request all go through here. issue() stores
 * the token's hash for the user's current address and belongs in the caller's transaction;
 * deliver() sends the link and belongs after the commit, so that a rolled-back change never
 * emails a link that cannot be redeemed. The raw token exists only between the two calls:
 * it must never reach a domain event, because events are stored in the outbox.
 */
final readonly class EmailVerificationIssuer
{
    /**
     * @param int $tokenTtlSeconds how long an issued token can be redeemed (auth.email_verification_token.ttl)
     */
    public function __construct(
        private EmailVerificationTokenRepositoryInterface $tokens,
        private EmailVerificationDeliveryInterface $delivery,
        private int $tokenTtlSeconds,
    ) {
        if ($tokenTtlSeconds < 60) {
            throw new \InvalidArgumentException('The email verification token lifetime must be at least one minute.');
        }
    }

    /**
     * Replaces the user's outstanding token with a new one for their current address.
     *
     * @return IssuedEmailVerificationToken|null null when there is nothing to verify: the
     *                                           address is verified or the account is disabled
     */
    public function issue(User $user): ?IssuedEmailVerificationToken
    {
        if ($user->isEmailVerified() || $user->isDisabled()) {
            return null;
        }

        $issued = new IssuedEmailVerificationToken(
            bin2hex(random_bytes(32)),
            new \DateTimeImmutable(sprintf('+%d seconds', $this->tokenTtlSeconds)),
        );
        $this->tokens->issue($user->getId(), Email::fromString($user->getEmail()), $issued->token, $issued->expiresAt);

        return $issued;
    }

    public function deliver(User $user, ?IssuedEmailVerificationToken $issued): void
    {
        if ($issued !== null) {
            $this->delivery->deliver($user, $issued->token, $issued->expiresAt);
        }
    }
}
