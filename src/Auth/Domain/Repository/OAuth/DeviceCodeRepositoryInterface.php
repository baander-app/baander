<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

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

    /**
     * Deletes device codes that expired before the cutoff, whatever their state.
     *
     * A code without an expiry never expires and is kept.
     *
     * @return int The number of deleted codes
     */
    public function deleteExpiredBefore(DateTimeImmutable $cutoff): int;
}
