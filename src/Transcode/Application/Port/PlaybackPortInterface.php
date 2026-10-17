<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;

interface PlaybackPortInterface extends PlaybackAccessInterface
{
    public function findSession(Uuid $videoId, PublicId $jobId): ?PublicId;

    public function start(Uuid $videoId): void;
}
