<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\Credential;

use App\Auth\Domain\Model\Credential\ThirdPartyCredential;
use App\Shared\Domain\Model\Uuid;

interface ThirdPartyCredentialRepositoryInterface
{
    public function save(ThirdPartyCredential $credential): void;

    public function findByUuid(Uuid $uuid): ?ThirdPartyCredential;

    public function findByUserAndProvider(Uuid $userId, string $provider): ?ThirdPartyCredential;

    /**
     * @return ThirdPartyCredential[]
     */
    public function findByUser(Uuid $userId): array;

    public function delete(ThirdPartyCredential $credential): void;
}