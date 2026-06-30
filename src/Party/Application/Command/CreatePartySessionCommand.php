<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class CreatePartySessionCommand
{
    public function __construct(
        private Uuid $hostUserId,
        private Uuid $videoId,
        private Uuid $transcodeJobId,
        private int $maxMembers = 10,
    ) {
    }

    public function getHostUserId(): Uuid { return $this->hostUserId; }
    public function getVideoId(): Uuid { return $this->videoId; }
    public function getTranscodeJobId(): Uuid { return $this->transcodeJobId; }
    public function getMaxMembers(): int { return $this->maxMembers; }
}
