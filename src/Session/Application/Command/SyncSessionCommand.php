<?php

declare(strict_types=1);

namespace App\Session\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncSessionCommand
{
    /**
     * @param array<string, mixed> $queue
     */
    public function __construct(
        private Uuid $userId,
        private Uuid $deviceId,
        private array $queue,
        private int $currentTrackIndex,
        private float $position,
        private string $playbackState,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getDeviceId(): Uuid
    {
        return $this->deviceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    public function getCurrentTrackIndex(): int
    {
        return $this->currentTrackIndex;
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function getPlaybackState(): string
    {
        return $this->playbackState;
    }
}
