<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;

interface PlaybackPortInterface extends PlaybackAccessInterface
{
    /**
     * @param list<PublicId> $jobIds
     * @return array<string, PublicId> This client's requested jobs mapped to their current session public IDs.
     */
    public function findSessions(Uuid $videoId, string $requestId, array $jobIds): array;

    /** @return array<string, PublicId> The admitted jobs mapped to their session public IDs. */
    public function start(Uuid $videoId, string $requestId): array;
}
