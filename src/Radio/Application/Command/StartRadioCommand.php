<?php

declare(strict_types=1);

namespace App\Radio\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class StartRadioCommand
{
    public function __construct(
        private Uuid $userId,
        private Uuid $stationId,
        private string $streamUrl,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getStationId(): Uuid
    {
        return $this->stationId;
    }

    public function getStreamUrl(): string
    {
        return $this->streamUrl;
    }
}
