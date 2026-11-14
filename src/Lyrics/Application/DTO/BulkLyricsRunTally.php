<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/**
 * The outcomes of one bulk lyrics run: the songs its walk skipped per cause, and what each
 * fetch it queued came to. A fetch counts once its worker has handled it, so while fetches are
 * still due the outcome counts add up to less than the queued count; a fetch skipped because
 * the run was cancelled never counts.
 */
final readonly class BulkLyricsRunTally
{
    public function __construct(
        /** The job-monitor job that ran the walk, or null for a run outside a job. */
        public ?string $jobId,
        /** When the walk finished queueing. */
        public \DateTimeImmutable $finishedAt,
        public int $queued,
        public int $found,
        public int $noMatch,
        /** Songs without a primary artist credit: skipped by the walk, or by a fetch after the credit was removed. */
        public int $skippedNoArtist,
        /** Songs whose length is missing or not positive, counted as for a missing artist. */
        public int $skippedNoLength,
        public int $rejected,
        public int $unavailable,
    ) {
    }

    /**
     * @param array<string, int> $counts the count of each outcome, keyed by its LyricsFetchOutcome value
     */
    public static function fromCounts(?string $jobId, \DateTimeImmutable $finishedAt, int $queued, array $counts): self
    {
        $count = static fn (LyricsFetchOutcome $outcome): int => $counts[$outcome->value] ?? 0;

        return new self(
            $jobId,
            $finishedAt,
            $queued,
            $count(LyricsFetchOutcome::Found),
            $count(LyricsFetchOutcome::NoMatch),
            $count(LyricsFetchOutcome::NoArtist),
            $count(LyricsFetchOutcome::NoLength),
            $count(LyricsFetchOutcome::Rejected),
            $count(LyricsFetchOutcome::Unavailable),
        );
    }

    /**
     * The `latestRun` of GET /api/admin/lyrics/sync-status.
     *
     * @return array{jobId: string|null, finishedAt: string, queued: int, found: int, noMatch: int, skippedNoArtist: int, skippedNoLength: int, rejected: int, unavailable: int}
     */
    public function toArray(): array
    {
        return [
            'jobId' => $this->jobId,
            'finishedAt' => $this->finishedAt->format(\DateTimeInterface::ATOM),
            'queued' => $this->queued,
            'found' => $this->found,
            'noMatch' => $this->noMatch,
            'skippedNoArtist' => $this->skippedNoArtist,
            'skippedNoLength' => $this->skippedNoLength,
            'rejected' => $this->rejected,
            'unavailable' => $this->unavailable,
        ];
    }
}
