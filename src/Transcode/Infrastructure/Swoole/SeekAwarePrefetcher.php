<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Port\SegmentCachePortInterface;
use Psr\Log\LoggerInterface;

/**
 * Seek-aware segment prefetcher running as a separate Swoole coroutine.
 *
 * Listens for playback position changes via Swoole\Coroutine\Channel.
 * On each position update, generates an expanding-ring segment priority list
 * (target, target-1, target+1, target-2, target+2, ...) and dispatches
 * segments not already in the booking table or cache.
 *
 * Only uses worker slots the encoding loop isn't using (leftover capacity).
 */
final class SeekAwarePrefetcher
{
    private const PREFETCH_RING_RADIUS = 5; // segments ahead/behind to prefetch

    public function __construct(
        private readonly SegmentBookingTable $bookingTable,
        private readonly SegmentCachePortInterface $cache,
        private readonly CpuProcessPool $processPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate expanding-ring segment order for a given target position.
     *
     * Order: target, target-1, target+1, target-2, target+2, ...
     *
     * @return list<int>
     */
    public function expandingRingOrder(int $targetSegment, int $totalSegments): array
    {
        $order = [];
        $visited = [];

        // Start with target
        if ($targetSegment >= 0 && $targetSegment < $totalSegments) {
            $order[] = $targetSegment;
            $visited[$targetSegment] = true;
        }

        // Expanding ring
        for ($radius = 1; $radius <= self::PREFETCH_RING_RADIUS; $radius++) {
            $before = $targetSegment - $radius;
            $after = $targetSegment + $radius;

            if ($before >= 0 && !isset($visited[$before])) {
                $order[] = $before;
                $visited[$before] = true;
            }

            if ($after < $totalSegments && !isset($visited[$after])) {
                $order[] = $after;
                $visited[$after] = true;
            }
        }

        return $order;
    }

    /**
     * Dispatch prefetch requests for segments around a playback position.
     *
     * Checks booking table and cache before dispatching each segment.
     * Failures are logged but never block playback.
     *
     * @param list<int> $completedSegments Segments already completed
     */
    public function prefetchAroundPosition(
        Uuid $jobId,
        int $targetSegment,
        int $totalSegments,
        array $completedSegments,
    ): int {
        $completedSet = array_flip($completedSegments);
        $order = $this->expandingRingOrder($targetSegment, $totalSegments);
        $dispatched = 0;

        foreach ($order as $segmentIndex) {
            // Skip already completed
            if (isset($completedSet[$segmentIndex])) {
                continue;
            }

            // Skip already in cache
            if ($this->cache->get($jobId, $segmentIndex) !== null) {
                continue;
            }

            // Skip already booked (in-flight)
            if ($this->bookingTable->isBooked($jobId, $segmentIndex)) {
                continue;
            }

            // Try to book and dispatch
            if ($this->bookingTable->book($jobId, $segmentIndex, 'prefetcher')) {
                try {
                    $this->dispatchPrefetch($jobId, $segmentIndex);
                    $dispatched++;
                } catch (\Throwable $e) {
                    $this->bookingTable->release($jobId, $segmentIndex);
                    $this->logger->warning('Prefetch dispatch failed', [
                        'jobId' => $jobId->toString(),
                        'segment' => $segmentIndex,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $dispatched;
    }

    private function dispatchPrefetch(Uuid $jobId, int $segmentIndex): void
    {
        // Prefetch uses the same encode_segment action as the main loop.
        // The pool worker handles it identically — the only difference is
        // the dispatcher (prefetcher vs main encoding loop).
        $resultKey = sprintf('encode_segment:%s:%d', $jobId->toString(), $segmentIndex);
        $payload = json_encode([
            'type' => 'encode_segment',
            'jobId' => $jobId->toString(),
            'segmentIndex' => $segmentIndex,
        ], JSON_THROW_ON_ERROR);

        $this->processPool->dispatch($payload, $resultKey);

        $this->logger->debug('Prefetch dispatched', [
            'jobId' => $jobId->toString(),
            'segment' => $segmentIndex,
        ]);
    }
}
