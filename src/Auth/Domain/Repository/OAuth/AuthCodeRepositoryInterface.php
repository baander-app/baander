<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use DateTimeImmutable;

interface AuthCodeRepositoryInterface
{
    public function save(AuthCode $authCode, bool $flush = true): void;

    public function findByCodeId(TokenId $codeId): ?AuthCode;

    /**
     * Atomically marks an unexpired, unused code as used.
     *
     * Returns false when the code was already redeemed, revoked or has expired,
     * so exactly one of several concurrent redemptions succeeds.
     */
    public function redeem(AuthCode $authCode): bool;

    /**
     * Deletes codes that expired before the cutoff, redeemed or not.
     *
     * A code without an expiry never expires and is kept.
     *
     * @return int The number of deleted codes
     */
    public function deleteExpiredBefore(DateTimeImmutable $cutoff): int;
}
