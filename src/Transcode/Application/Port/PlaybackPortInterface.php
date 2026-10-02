<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlaybackPortInterface
{
    public function assertAccess(Uuid $videoId): void;

    public function start(Uuid $videoId): void;
}
