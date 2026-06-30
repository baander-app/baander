<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;

/**
 * Listens for PlaybackPositionChanged events and triggers prefetch
 * for segments around the new playback position.
 *
 * Registered as a second event listener alongside PlaybackPositionChangedListener
 * (which signals the encoding loop via SeekSignalBroker).
 */
final class PrefetchPositionListener
{
    public function __construct(
        private readonly SeekAwarePrefetcher $prefetcher,
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PlaybackPositionChanged $event): void
    {
        CoWrapper::go(function () use ($event): void {
            $this->handlePositionChange($event);
        });
    }

    private function handlePositionChange(PlaybackPositionChanged $event): void
    {
        $job = $this->jobPort->findByUuid($event->getJobId());
        if ($job === null) {
            return;
        }

        // Only prefetch for active sessions
        if ($job->getStatus() !== TranscodeStatus::InProgress) {
            return;
        }

        $targetSegment = (int) floor($event->getPosition() / 6.0); // 6s CMAF segments
        $totalSegments = $job->getTotalSegments();

        if ($totalSegments === 0) {
            return;
        }

        $dispatched = $this->prefetcher->prefetchAroundPosition(
            $job->getId(),
            $targetSegment,
            $totalSegments,
            array_map('intval', array_keys($job->getSegmentMap())),
        );

        if ($dispatched > 0) {
            $this->logger->info('Prefetch dispatched segments', [
                'jobId' => $job->getId()->toString(),
                'position' => $event->getPosition(),
                'targetSegment' => $targetSegment,
                'dispatched' => $dispatched,
            ]);
        }
    }
}
