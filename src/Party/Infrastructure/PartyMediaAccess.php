<?php

declare(strict_types=1);

namespace App\Party\Infrastructure;

use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Exception\TranscodeJobVideoMismatchException;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\PlaybackAccessInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Applies Transcode's playback authority, the check that also guards stream signing and manifests.
 */
final readonly class PartyMediaAccess implements PartyMediaAccessPortInterface
{
    public function __construct(
        private PlaybackAccessInterface $playback,
    ) {
    }

    public function assertHostCanPlay(Uuid $videoId, ?Uuid $transcodeJobId): void
    {
        if (!$this->canPlay($videoId)) {
            throw PartyMediaNotFoundException::video();
        }

        if ($transcodeJobId === null) {
            return;
        }

        $jobVideoId = $this->playback->findTranscodeJobVideoId($transcodeJobId);
        // A job of a video the host may not play is reported as missing, like the video itself.
        if ($jobVideoId === null || !(self::same($jobVideoId, $videoId) || $this->canPlay($jobVideoId))) {
            throw PartyMediaNotFoundException::transcodeJob();
        }

        if (!self::same($jobVideoId, $videoId)) {
            throw new TranscodeJobVideoMismatchException();
        }
    }

    /** The request may spell a UUID in upper case; PostgreSQL returns it in lower case. */
    private static function same(Uuid $a, Uuid $b): bool
    {
        return strcasecmp($a->toString(), $b->toString()) === 0;
    }

    private function canPlay(Uuid $videoId): bool
    {
        try {
            $this->playback->assertAccess($videoId);
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }
}
