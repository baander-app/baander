<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Service;

use App\Scheduler\Application\Port\SchedulerOccurrenceDispatchStoreInterface;
use App\Scheduler\Application\Port\SchedulerOccurrencePublisherInterface;

/** Bounded, at-least-once delivery of already committed scheduler intents. */
final readonly class SchedulerOccurrenceRelay
{
    public function __construct(
        private SchedulerOccurrenceDispatchStoreInterface $dispatches,
        private SchedulerOccurrencePublisherInterface $publisher,
    ) {}

    /** Return accepted/recorded sends; errors remain visible after attempting the whole reserved batch. */
    public function dispatchPending(int $limit = 100, int $retrySeconds = 60): int
    {
        $claims = $this->dispatches->claimPending($limit, $retrySeconds);
        $published = 0;
        $failed = 0;
        $firstError = null;
        foreach ($claims as $claim) {
            try {
                $this->publisher->publish($claim->occurrenceId);
                if (!$this->dispatches->markPublished($claim)) {
                    throw new \RuntimeException('Scheduler occurrence publication receipt was not accepted.');
                }
                ++$published;
            } catch (\Throwable $error) {
                // A poison delivery must not starve other already reserved intents.
                // No reset: failed/uncertain handoffs recover after their reservation expires.
                ++$failed;
                $firstError ??= $error;
            }
        }
        if ($firstError !== null) {
            throw new \RuntimeException(sprintf('Scheduler occurrence dispatch failed for %d of %d reservations.', $failed, count($claims)), previous: $firstError);
        }
        return $published;
    }
}
