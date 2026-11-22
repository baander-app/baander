<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Exception\TranscodeJobVideoMismatchException;
use App\Shared\Domain\Model\Uuid;

/**
 * Checks the media a party refers to against the playback authority.
 */
interface PartyMediaAccessPortInterface
{
    /**
     * Confirms that the authenticated user may play the video.
     *
     * @throws PartyMediaNotFoundException when the video does not exist or the user may not play it
     */
    public function assertCanPlay(Uuid $videoId): void;

    /**
     * Confirms that the host, the authenticated user who creates the party, may play the video, and
     * that the transcode job, when given, is the host's own job of that video.
     *
     * @throws PartyMediaNotFoundException when the video or job does not exist, another user owns the job,
     *                                     or the host may not play its video
     * @throws TranscodeJobVideoMismatchException when the host's job encodes another video the host may play
     */
    public function assertHostCanPlay(Uuid $hostUserId, Uuid $videoId, ?Uuid $transcodeJobId): void;

    /**
     * Whether the user owns the transcode job, whose encoder a party then steers on the owner's behalf.
     */
    public function ownsTranscodeJob(Uuid $userId, Uuid $transcodeJobId): bool;
}
