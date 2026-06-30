<?php

declare(strict_types=1);

namespace App\Party\Domain\Model;

use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for PartyMember aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class PartyMemberState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $userId,
        public readonly Uuid $sessionId,
        public readonly DateTimeImmutable $joinedAt,
        public MemberRole $role = MemberRole::Member,
        public ?string $audioProfileId = null,
        public ?string $subtitleTrackId = null,
        public float $lastSyncPosition = 0.0,
        public ?DateTimeImmutable $lastSyncAt = null,
        public float $jitterCompensation = 0.0,
        public bool $isConnected = true,
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
