<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;

interface RefreshTokenRepositoryInterface
{
    public function save(RefreshToken $refreshToken): void;

    public function findByTokenId(TokenId $tokenId): ?RefreshToken;

    /**
     * @return RefreshToken[]
     */
    public function findByChainId(ChainId $chainId): array;

    public function revokeByChainId(ChainId $chainId): void;
}
