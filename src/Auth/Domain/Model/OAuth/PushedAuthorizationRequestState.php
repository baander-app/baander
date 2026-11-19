<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for the PushedAuthorizationRequest aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class PushedAuthorizationRequestState
{
    /** @param list<Scope> $scopes */
    public function __construct(
        public readonly Uuid $id,
        public readonly TokenId $requestId,
        public readonly Uuid $clientId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly ?string $state,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public readonly string $dpopJkt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $consumedAt = null,
    ) {
    }
}
