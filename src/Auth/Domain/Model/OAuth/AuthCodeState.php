<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
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
        /** The code itself: known only to the request that issued it; storage keeps its hash. */
        public readonly ?TokenId $codeId,
        public readonly User $user,
        public readonly Client $client,
        array $scopes,
        public ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public readonly string $dpopJkt,
        public bool $revoked = false,
        public ?ChainId $issuedChainId = null,
        /** The credential epoch of the token that approved the code; the exchange refuses a later one. */
        public readonly int $credentialEpoch = 0,
    ) {
        $this->scopes = $scopes;
    }
}
