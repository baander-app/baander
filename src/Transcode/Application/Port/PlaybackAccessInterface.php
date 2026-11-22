<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The playback authority other contexts may consult before they refer to a video or its transcode job.
 */
interface PlaybackAccessInterface
{
    /**
     * @throws AccessDeniedException when the video does not exist or the authenticated user may not play it.
     *                               Both cases throw the same exception, so a caller cannot tell them apart.
     */
    public function assertAccess(Uuid $videoId): void;

    /**
     * The video of a transcode job the given user owns, or null when no such job exists or it is not
     * the user's: another user's job and a missing one look the same. Does not check playback access.
     */
    public function findOwnedTranscodeJobVideoId(Uuid $jobId, Uuid $ownerUserId): ?Uuid;
}
