<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Shared\Domain\Model\Uuid;

interface TokenMetadataRepositoryInterface
{
    public function save(TokenMetadata $metadata): void;

    public function findByTokenId(Uuid $tokenId): ?TokenMetadata;

    public function deleteByTokenId(Uuid $tokenId): void;
}
