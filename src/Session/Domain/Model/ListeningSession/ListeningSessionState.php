<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\ListeningSession;

use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for ListeningSession aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class ListeningSessionState
{
    /**
     * @param array<array-key, mixed> $queue
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $userId,
        public ?Uuid $activeDeviceId,
        public array $queue,
        public int $currentTrackIndex,
        public float $position,
        public string $playbackState,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $lastUsedAt = null,
        public int $revision = 0,
        public ?PlaybackMedia $media = null,
        public PlaybackState $desiredState = PlaybackState::Stopped,
        public ObservedPlaybackState $observedState = ObservedPlaybackState::Unknown,
        public ?Uuid $selectedDestinationId = null,
        public ?Uuid $activeIncarnationId = null,
    ) {}
}
