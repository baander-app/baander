<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\LrclibRejected;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
use App\Lyrics\Application\DTO\LyricsFetchResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Lyrics\Application\Service\LyricsLookupRequirements;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles FetchLyricsCommand.
 *
 * Orchestrates: resolve song signature → check local cache → fetch from LRCLIB → persist.
 *
 * A song that has lyrics keeps them: the fetch returns them without asking LRCLIB. A song
 * without a primary artist credit, or whose length is missing or not positive, is not looked
 * up. The result names its outcome: found, no match, one of those two causes, a request LRCLIB
 * rejected, or LRCLIB unavailable. The handler never throws for any of them, so a queued
 * fetch (bulk or automatic) ends without a retry. The per-song API route and
 * app:song:lyrics:fetch dispatch it synchronously and report every outcome but found and no
 * match as an error.
 *
 * A fetch that a bulk run queued is skipped when that run was cancelled, and releases its
 * song's queued mark once handled, so a later run can queue the song again. Its outcome is
 * counted in the run's tally, once per song however often the fetch is delivered; a fetch
 * skipped because its run was cancelled, or whose song no longer exists, counts toward no
 * outcome.
 */
#[AsMessageHandler]
final class FetchLyricsHandler
{
    public function __construct(
        private readonly SongLookupInterface $songs,
        private readonly LrclibClientInterface $lrclibClient,
        private readonly LyricsRepositoryInterface $lyricsRepository,
        private readonly LoggerInterface $logger,
        private readonly QueuedLyricsFetchesInterface $queuedFetches,
    ) {
    }

    public function __invoke(FetchLyricsCommand $command): LyricsFetchResult
    {
        $songId = $command->getSongId();
        $runId = $command->getBulkRunId();
        if ($runId === null) {
            return $this->fetch($songId) ?? LyricsFetchResult::noMatch();
        }

        try {
            if ($this->queuedFetches->isRunCancelled($runId)) {
                $this->logger->info('Bulk lyrics fetch was cancelled, skipping song', [
                    'song_id' => $songId->toString(),
                    'bulk_run_id' => $runId->toString(),
                ]);

                return LyricsFetchResult::noMatch();
            }

            $result = $this->fetch($songId);
            if ($result === null) {
                return LyricsFetchResult::noMatch();
            }
            $this->queuedFetches->recordOutcome($runId, $songId, $result->outcome);

            return $result;
        } finally {
            $this->queuedFetches->clearQueued($songId);
        }
    }

    /** @return LyricsFetchResult|null null when the song no longer exists */
    private function fetch(Uuid $songId): ?LyricsFetchResult
    {
        // 1. Find song
        $signature = $this->songs->findLyricSignature($songId);
        if ($signature === null) {
            $this->logger->warning('Song not found for lyrics fetch, skipping', [
                'song_id' => $songId->toString(),
            ]);

            return null;
        }

        // 2. Check if lyrics already exist
        $existing = $this->lyricsRepository->findBySongId($songId);
        if ($existing !== null) {
            $this->logger->debug('Lyrics already exist for song, skipping fetch', [
                'song_id' => $songId->toString(),
            ]);

            return LyricsFetchResult::found($existing);
        }

        // 3. Require an artist name and a positive length, which LRCLIB matches within ±2 seconds
        $notAttempted = LyricsLookupRequirements::unmetBy($signature);
        if ($notAttempted === LyricsFetchOutcome::NoArtist) {
            $this->logger->info('Song has no artist, lyrics lookup not attempted', [
                'song_id' => $songId->toString(),
            ]);

            return LyricsFetchResult::noArtist();
        }
        if ($notAttempted === LyricsFetchOutcome::NoLength) {
            $this->logger->info('Song has no length, lyrics lookup not attempted', [
                'song_id' => $songId->toString(),
                'length' => $signature->duration,
            ]);

            return LyricsFetchResult::noLength();
        }
        // Both are set once the requirements hold.
        $artistName = (string) $signature->artistName;
        $duration = (float) $signature->duration;

        // 4. Album name is optional
        $albumName = $signature->albumTitle ?? '';

        // 5. Try cached endpoint first, then full endpoint, which also answers when the cached one failed
        $result = $this->lrclibClient->getBySignatureCached(
            $signature->title,
            $artistName,
            $albumName,
            $duration,
        );

        if (!$result instanceof LrclibResult) {
            $result = $this->lrclibClient->getBySignature(
                $signature->title,
                $artistName,
                $albumName,
                $duration,
            );
        }

        // 6. LRCLIB unavailable: nothing is decided, and a later fetch may succeed
        if ($result instanceof LrclibUnavailable) {
            $this->logger->warning('LRCLIB unavailable, no lyrics fetched for song', [
                'song_id' => $songId->toString(),
                'reason' => $result->reason,
            ]);

            return LyricsFetchResult::providerUnavailable();
        }

        // 7. LRCLIB rejected the request: the same request gets the same answer
        if ($result instanceof LrclibRejected) {
            $this->logger->warning('LRCLIB rejected the lyrics request for song', [
                'song_id' => $songId->toString(),
                'status' => $result->status,
                'duration' => $duration,
            ]);

            return LyricsFetchResult::rejected();
        }

        // 8. No lyrics found
        if ($result === null) {
            $this->logger->info('No lyrics found on LRCLIB for song', [
                'song_id' => $songId->toString(),
                'title' => $signature->title,
                'artist' => $artistName,
            ]);

            return LyricsFetchResult::noMatch();
        }

        // 9. Create and persist lyrics; an apply or another fetch may have stored some meanwhile
        $lyrics = $result->toLyrics($songId);
        if (!$this->lyricsRepository->add($lyrics)) {
            $this->logger->debug('Lyrics were stored for song during the fetch, returning them', [
                'song_id' => $songId->toString(),
            ]);
            $stored = $this->lyricsRepository->findBySongId($songId);

            return $stored !== null ? LyricsFetchResult::found($stored) : LyricsFetchResult::noMatch();
        }

        $this->logger->info('Fetched and stored lyrics from LRCLIB', [
            'song_id' => $songId->toString(),
            'lrclib_id' => $result->id,
            'has_synced' => $result->syncedLyrics !== null,
            'instrumental' => $result->instrumental,
        ]);

        return LyricsFetchResult::found($lyrics);
    }
}
