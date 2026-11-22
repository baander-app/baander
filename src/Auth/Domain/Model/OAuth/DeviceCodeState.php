<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for DeviceCode aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class DeviceCodeState
{
    /** @var Scope[] */
    public array $scopes;

    /** @param array<array-key, Scope> $scopes */
    public function __construct(
        public readonly Uuid $id,
        /** The device code itself: known only to the request that issued it; storage keeps its hash. */
        public readonly ?TokenId $deviceCode,
        public readonly string $userCode,
        public ?User $user,
        public readonly Client $client,
        array $scopes,
        public readonly string $verificationUri,
        public readonly ?string $verificationUriComplete,
        public ?DateTimeImmutable $expiresAt,
        public int $interval,
        public ?DateTimeImmutable $lastPolledAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $approved = false,
        public bool $denied = false,
        public ?DateTimeImmutable $consumedAt = null,
        /** The credential epoch of the token that approved the code; null until it is approved. */
        public ?int $credentialEpoch = null,
    ) {
        $this->scopes = $scopes;
    }
}
