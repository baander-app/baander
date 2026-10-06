<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenMetadata;

interface TokenMetadataRepositoryInterface
{
    /** Stores metadata for the access token whose primary key is TokenMetadata::getTokenId(). */
    public function save(TokenMetadata $metadata): void;

    /** Finds metadata by the access token's public identifier (the JWT jti). */
    public function findByTokenId(TokenId $tokenId): ?TokenMetadata;

    /** Deletes metadata by the access token's public identifier (the JWT jti). */
    public function deleteByTokenId(TokenId $tokenId): void;
}
