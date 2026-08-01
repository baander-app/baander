<?php

declare(strict_types=1);

namespace App\Session\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class ClaimSessionCommand
{
    /**
     * @param array<string, mixed>|null $queue
     */
    public function __construct(
        private Uuid $userId,
        private Uuid $deviceId,
        private ?array $queue = null,
        private ?int $currentTrackIndex = null,
        private ?float $position = null,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getDeviceId(): Uuid { return $this->deviceId; }
    public function getQueue(): ?array { return $this->queue; }
    public function getCurrentTrackIndex(): ?int { return $this->currentTrackIndex; }
    public function getPosition(): ?float { return $this->position; }
}
