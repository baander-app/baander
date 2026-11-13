<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\PlaybackEndReason;

interface PlaybackPortInterface extends PlaybackAccessInterface
{
    /**
     * A session that was cancelled or has failed is no longer held and is left out, also while its
     * job and runtime remain.
     *
     * @param list<PublicId> $jobIds
     * @return array<string, PublicId> This client's requested jobs mapped to the session public IDs it still holds.
     */
    public function findSessions(Uuid $videoId, string $requestId, array $jobIds): array;

    /**
     * Why this client holds none of the sessions of the named jobs any more.
     *
     * @param list<PublicId> $jobIds
     */
    public function endReason(Uuid $videoId, string $requestId, array $jobIds): PlaybackEndReason;

    /**
     * Records that the client still holds these sessions. A stream signing calls
     * it: a paused player sends no playback reports, and stale-session expiry
     * must not end a session whose client still renews its stream URL.
     *
     * @param list<PublicId> $sessionIds
     */
    public function touchSessions(array $sessionIds): void;

    /** @return array<string, PublicId> The admitted jobs mapped to their session public IDs. */
    public function start(Uuid $videoId, string $requestId): array;
}
