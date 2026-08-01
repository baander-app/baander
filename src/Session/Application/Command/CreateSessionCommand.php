<?php

declare(strict_types=1);

namespace App\Session\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class CreateSessionCommand
{
    /**
     * @param array<string, mixed> $queue
     */
    public function __construct(
        private Uuid $userId,
        private array $queue,
        private int $currentTrackIndex,
        private float $position,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
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
}
