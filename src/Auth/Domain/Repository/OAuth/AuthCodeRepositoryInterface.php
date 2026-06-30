<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\TokenId;

interface AuthCodeRepositoryInterface
{
    public function save(AuthCode $authCode): void;

    public function findByCodeId(TokenId $codeId): ?AuthCode;
}
