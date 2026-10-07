<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for AuthCode aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class AuthCodeState
{
    /** @var Scope[] */
    public array $scopes;

    /** @param array<array-key, Scope> $scopes */
    public function __construct(
        public readonly Uuid $id,
        public readonly TokenId $codeId,
        public readonly User $user,
        public readonly Client $client,
        array $scopes,
        public ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public bool $revoked = false,
    ) {
        $this->scopes = $scopes;
    }
}
