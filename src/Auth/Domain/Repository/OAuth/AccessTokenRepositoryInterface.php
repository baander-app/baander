<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;

interface AccessTokenRepositoryInterface
{
    public function save(AccessToken $accessToken): void;

    public function findByTokenId(TokenId $tokenId): ?AccessToken;

    public function revokeByChainId(ChainId $chainId): void;

    public function revokeForUser(User $user): void;
}
