<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\LiveDeliveryAccessPort;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;

/**
 * Checks the job owner's current access the way completed delivery does: the owner's library
 * scope must still show the job's video. Each owner and video pair is checked once.
 */
final readonly class LiveDeliveryAccessAdapter implements LiveDeliveryAccessPort
{
    private const string UNAVAILABLE = 'Playback media is unavailable.';

    public function __construct(
        private TranscodeJobRepositoryInterface $jobs,
        private VideoPortInterface $videos,
        private PlaybackMediaAccessInterface $access,
    ) {
    }

    #[\Override]
    public function assertOwnerMayStream(PublicId $jobId): void
    {
        $this->assertOwnersMayStream([$jobId]);
    }

    #[\Override]
    public function assertOwnersMayStream(array $jobIds): void
    {
        $grants = [];
        foreach ($jobIds as $jobId) {
            [$owner, $video] = $this->ownerAndVideo($jobId);
            $grants[$owner->toString() . ' ' . $video->toString()] = [$owner, $video];
        }
        foreach ($grants as [$owner, $videoId]) {
            $video = $this->videos->findByUuid($videoId) ?? throw new ConflictException(self::UNAVAILABLE);
            $this->access->assertVideoAccess($owner, $video->getPublicId());
        }
    }

    /** @return array{Uuid, Uuid} the job's owner and video */
    private function ownerAndVideo(PublicId $jobId): array
    {
        $job = $this->jobs->findByPublicId($jobId);
        $owner = $job?->getOwnerUserId();
        if ($job === null || $owner === null) {
            throw new ConflictException(self::UNAVAILABLE);
        }

        return [$owner, $job->getVideoId()];
    }
}
