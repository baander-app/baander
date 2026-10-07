<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Shared\Domain\Model\Uuid;

interface DeviceCodeRepositoryInterface
{
    public function save(DeviceCode $deviceCode, bool $flush = true): void;

    public function findById(Uuid $id): ?DeviceCode;

    public function findByDeviceCode(TokenId $deviceCode): ?DeviceCode;

    public function findByUserCode(string $userCode): ?DeviceCode;

    /**
     * Atomically consumes an approved, unexpired, unconsumed device code.
     *
     * Returns false when another poll already redeemed it, so exactly one
     * token pair is issued per approval.
     */
    public function redeem(DeviceCode $deviceCode): bool;
}
