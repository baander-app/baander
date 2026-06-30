<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Shared\Domain\Model\Uuid;

interface DeviceCodeRepositoryInterface
{
    public function save(DeviceCode $deviceCode): void;

    public function findById(Uuid $id): ?DeviceCode;

    public function findByDeviceCode(TokenId $deviceCode): ?DeviceCode;

    public function findByUserCode(string $userCode): ?DeviceCode;
}
