<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Exception\TranscodeJobVideoMismatchException;
use App\Shared\Domain\Model\Uuid;

/**
 * Checks the media a new party refers to against the playback authority.
 */
interface PartyMediaAccessPortInterface
{
    /**
     * Confirms that the authenticated user, who becomes the host, may play the video, and that the
     * transcode job, when given, encodes that video.
     *
     * @throws PartyMediaNotFoundException when the video or job does not exist, or the user may not play its video
     * @throws TranscodeJobVideoMismatchException when the job encodes another video the user may play
     */
    public function assertHostCanPlay(Uuid $videoId, ?Uuid $transcodeJobId): void;
}
