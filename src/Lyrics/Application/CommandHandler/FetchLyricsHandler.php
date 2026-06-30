<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles FetchLyricsCommand.
 *
 * Orchestrates: check local cache → resolve song metadata → fetch from LRCLIB → persist.
 */
#[AsMessageHandler]
final class FetchLyricsHandler
{
    public function __construct(
        private readonly SongPortInterface $songPort,
        private readonly AlbumPortInterface $albumPort,
        private readonly LrclibClientInterface $lrclibClient,
        private readonly LyricsRepositoryInterface $lyricsRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchLyricsCommand $command): ?Lyrics
    {
        $songId = $command->getSongId();

        // 1. Find song
        $song = $this->songPort->findByUuid($songId);
        if ($song === null) {
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

            return $existing;
        }

        // 3. Resolve artist name
        $artistName = $this->songPort->getArtistNameForSong($songId);
        if ($artistName === null || trim($artistName) === '') {
            $this->logger->debug('No artist name found for song, cannot perform signature lookup', [
                'song_id' => $songId->toString(),
            ]);

            return null;
        }

        // 4. Resolve album name
        $album = $this->albumPort->findByUuid($song->getAlbumId());
        $albumName = $album?->getTitle() ?? '';

        // 5. Check duration — required for LRCLIB signature lookup (±2 seconds tolerance)
        $duration = $song->getLength();
        if ($duration === null) {
            $this->logger->debug('Song has no duration, cannot perform signature lookup', [
                'song_id' => $songId->toString(),
            ]);

            return null;
        }

        // 6. Try cached endpoint first, then full endpoint
        $result = $this->lrclibClient->getBySignatureCached(
            $song->getTitle(),
            $artistName,
            $albumName,
            $duration,
        );

        if ($result === null) {
            $result = $this->lrclibClient->getBySignature(
                $song->getTitle(),
                $artistName,
                $albumName,
                $duration,
            );
        }

        // 7. No lyrics found
        if ($result === null) {
            $this->logger->info('No lyrics found on LRCLIB for song', [
                'song_id' => $songId->toString(),
                'title' => $song->getTitle(),
                'artist' => $artistName,
            ]);

            return null;
        }

        // 8. Create and persist lyrics
        $lyrics = $this->createLyricsFromResult($result, $songId);
        $this->lyricsRepository->save($lyrics);

        $this->logger->info('Fetched and stored lyrics from LRCLIB', [
            'song_id' => $songId->toString(),
            'lrclib_id' => $result->id,
            'has_synced' => $result->syncedLyrics !== null,
            'instrumental' => $result->instrumental,
        ]);

        return $lyrics;
    }

    private function createLyricsFromResult(LrclibResult $result, $songId): Lyrics
    {
        return Lyrics::create(
            songId: $songId,
            lyrics: $result->plainLyrics ?? '',
            source: 'lrclib',
            sourceUrl: null,
            isInstrumental: $result->instrumental,
            syncedLyrics: $result->syncedLyrics,
            lrclibId: $result->id,
        );
    }
}
