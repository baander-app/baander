<?php

declare(strict_types=1);

namespace App\Session\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class SessionPlaybackCommand
{
    /**
     * @param array<string, mixed>|null $queue
     */
    public function __construct(
        private Uuid $userId,
        private Uuid $deviceId,
        private string $action,
        private ?float $position = null,
        private ?array $queue = null,
        private ?int $currentTrackIndex = null,
        private ?string $playbackState = null,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getDeviceId(): Uuid { return $this->deviceId; }
    public function getAction(): string { return $this->action; }
    public function getPosition(): ?float { return $this->position; }
    public function getQueue(): ?array { return $this->queue; }
    public function getCurrentTrackIndex(): ?int { return $this->currentTrackIndex; }
    public function getPlaybackState(): ?string { return $this->playbackState; }
}
