<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;

interface PlaybackPortInterface extends PlaybackAccessInterface
{
    /** @return array<string, PublicId> Owned job public IDs mapped to their current session public IDs. */
    public function findSessions(Uuid $videoId): array;

    public function start(Uuid $videoId): void;
}
