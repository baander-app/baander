<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlaybackPortInterface extends PlaybackAccessInterface
{
    public function start(Uuid $videoId): void;
}
