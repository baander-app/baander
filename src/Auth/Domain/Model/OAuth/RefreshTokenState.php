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
        public readonly TokenId $tokenId,
        public readonly AccessToken $accessToken,
        public readonly ?ChainId $chainId,
        public ?RefreshToken $previousRefreshToken,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $revoked = false,
    ) {
    }
}
