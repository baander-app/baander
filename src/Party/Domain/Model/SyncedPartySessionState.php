<?php

declare(strict_types=1);

namespace App\Party\Domain\Model;

use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for SyncedPartySession aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class SyncedPartySessionState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public Uuid $hostUserId,
        public readonly Uuid $videoId,
        public readonly Uuid $transcodeJobId,
        public readonly int $maxMembers,
        public PlaybackState $playbackState = PlaybackState::Stopped,
        public float $wallClockPosition = 0.0,
        public ?DateTimeImmutable $playbackStartedAt = null,
        public ?float $pausedAtPosition = null,
        public bool $isActive = true,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
