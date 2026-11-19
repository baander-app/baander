<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for RefreshToken aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class RefreshTokenState
{
    public function __construct(
        public readonly Uuid $id,
        /** The token itself: known only to the request that issued it; storage keeps its hash. */
        public readonly ?TokenId $tokenId,
        public readonly AccessToken $accessToken,
        public readonly ?ChainId $chainId,
        public ?RefreshToken $previousRefreshToken,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $revoked = false,
        /** When the chain ends however recently it rotated; null only for a token outside any chain. */
        public readonly ?DateTimeImmutable $chainExpiresAt = null,
        /** The user's credential epoch the chain was issued under. */
        public readonly int $credentialEpoch = 0,
    ) {
    }
}
