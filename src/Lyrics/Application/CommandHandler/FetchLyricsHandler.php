<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\DTO\LyricsFetchResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles FetchLyricsCommand.
 *
 * Orchestrates: resolve song signature → check local cache → fetch from LRCLIB → persist.
 *
 * A song that has lyrics keeps them: the fetch returns them without asking LRCLIB. The
 * result tells found, not found and LRCLIB unavailable apart, and the handler never throws
 * for an outage, so a queued fetch (bulk or automatic) ends without a retry. The per-song
 * API route and app:song:lyrics:fetch dispatch it synchronously and report an outage.
 *
 * A fetch that a bulk run queued is skipped when that run was cancelled, and releases its
 * song's queued mark once handled, so a later run can queue the song again.
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
            return $this->fetch($songId);
        }

        try {
            if ($this->queuedFetches->isRunCancelled($runId)) {
                $this->logger->info('Bulk lyrics fetch was cancelled, skipping song', [
                    'song_id' => $songId->toString(),
                    'bulk_run_id' => $runId->toString(),
                ]);

                return LyricsFetchResult::notFound();
            }

            return $this->fetch($songId);
        } finally {
            $this->queuedFetches->clearQueued($songId);
        }
    }

    private function fetch(Uuid $songId): LyricsFetchResult
    {
        // 1. Find song
        $signature = $this->songs->findLyricSignature($songId);
        if ($signature === null) {
            $this->logger->warning('Song not found for lyrics fetch, skipping', [
                'song_id' => $songId->toString(),
            ]);

            return LyricsFetchResult::notFound();
        }

        // 2. Check if lyrics already exist
        $existing = $this->lyricsRepository->findBySongId($songId);
        if ($existing !== null) {
            $this->logger->debug('Lyrics already exist for song, skipping fetch', [
                'song_id' => $songId->toString(),
            ]);

            return LyricsFetchResult::found($existing);
        }

        // 3. Require an artist name
        $artistName = $signature->artistName;
        if ($artistName === null || trim($artistName) === '') {
            $this->logger->debug('No artist name found for song, cannot perform signature lookup', [
                'song_id' => $songId->toString(),
            ]);

            return LyricsFetchResult::notFound();
        }

        // 4. Album name is optional
        $albumName = $signature->albumTitle ?? '';

        // 5. Check duration — required for LRCLIB signature lookup (±2 seconds tolerance)
        $duration = $signature->duration;
        if ($duration === null) {
            $this->logger->debug('Song has no duration, cannot perform signature lookup', [
                'song_id' => $songId->toString(),
            ]);

            return LyricsFetchResult::notFound();
        }

        // 6. Try cached endpoint first, then full endpoint, which also answers when the cached one failed
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

        // 7. LRCLIB unavailable: nothing is decided, and a later fetch may succeed
        if ($result instanceof LrclibUnavailable) {
            $this->logger->warning('LRCLIB unavailable, no lyrics fetched for song', [
                'song_id' => $songId->toString(),
                'reason' => $result->reason,
            ]);

            return LyricsFetchResult::providerUnavailable();
        }

        // 8. No lyrics found
        if ($result === null) {
            $this->logger->info('No lyrics found on LRCLIB for song', [
                'song_id' => $songId->toString(),
                'title' => $signature->title,
                'artist' => $artistName,
            ]);

            return LyricsFetchResult::notFound();
        }

        // 9. Create and persist lyrics
        $lyrics = $result->toLyrics($songId);
        $this->lyricsRepository->save($lyrics);

        $this->logger->info('Fetched and stored lyrics from LRCLIB', [
            'song_id' => $songId->toString(),
            'lrclib_id' => $result->id,
            'has_synced' => $result->syncedLyrics !== null,
            'instrumental' => $result->instrumental,
        ]);

        return LyricsFetchResult::found($lyrics);
    }
}
