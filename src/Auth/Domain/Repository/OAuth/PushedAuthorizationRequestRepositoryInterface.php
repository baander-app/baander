<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

interface PushedAuthorizationRequestRepositoryInterface
{
    public function save(PushedAuthorizationRequest $request): void;

    /** Looking a request up does not use it up; an expired or used request is returned as stored. */
    public function findByRequestId(TokenId $requestId): ?PushedAuthorizationRequest;

    /**
     * Atomically uses up an unexpired, unused request that the given client pushed.
     *
     * Returns false when the request is unknown, expired, already used or was pushed
     * by another client, so exactly one of several concurrent decisions succeeds.
     * Expiry is judged by the database clock.
     */
    public function consume(TokenId $requestId, Uuid $clientId): bool;

    /**
     * Deletes requests that expired before the cutoff, used or not.
     *
     * @return int The number of deleted requests
     */
    public function deleteExpiredBefore(DateTimeImmutable $cutoff): int;
}
