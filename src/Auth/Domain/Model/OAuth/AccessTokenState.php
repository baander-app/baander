<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for AccessToken aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class AccessTokenState
{
    /** @var Scope[] */
    public array $scopes;

    public function __construct(
        public readonly Uuid $id,
        public readonly TokenId $tokenId,
        public ?User $user,
        public readonly Client $client,
        public ?string $name,
        array $scopes,
        public ?ChainId $chainId,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastRefreshedAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $revoked = false,
    ) {
        $this->scopes = $scopes;
    }
}
