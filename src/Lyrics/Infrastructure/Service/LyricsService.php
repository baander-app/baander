<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Service;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Application\Port\LyricsPortInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Infrastructure implementation of LyricsPortInterface.
 *
 * Delegates fetch operations to FetchLyricsHandler and provides
 * direct access to cached lyrics and LRCLIB search.
 */
final class LyricsService implements LyricsPortInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LyricsRepositoryInterface $lyricsRepository,
        private readonly LrclibClientInterface $lrclibClient,
        private readonly SongPortInterface $songPort,
        private readonly AlbumPortInterface $albumPort,
    ) {
    }

    public function findBySongId(Uuid $songId): ?Lyrics
    {
        $this->logger->debug('Fetching lyrics by song ID', [
            'song_id' => $songId->toString(),
        ]);

        return $this->lyricsRepository->findBySongId($songId);
    }

    public function fetchAndStore(Uuid $songId): ?Lyrics
    {
        $this->logger->debug('Fetching and storing lyrics for song', [
            'song_id' => $songId->toString(),
        ]);

        // Check cache first
        $existing = $this->lyricsRepository->findBySongId($songId);
        if ($existing !== null) {
            $this->logger->debug('Lyrics already cached for song', [
                'song_id' => $songId->toString(),
            ]);

            return $existing;
        }

        // Resolve song metadata
        $song = $this->songPort->findByUuid($songId);
        if ($song === null) {
            $this->logger->warning('Song not found for lyrics fetch', [
                'song_id' => $songId->toString(),
            ]);

            return null;
        }

        $artistName = $this->songPort->getArtistNameForSong($songId);
        if ($artistName === null || trim($artistName) === '') {
            $this->logger->debug('No artist name for song, cannot fetch lyrics', [
                'song_id' => $songId->toString(),
            ]);

            return null;
        }

        $duration = $song->getLength();
        if ($duration === null) {
            $this->logger->debug('Song has no duration, cannot fetch lyrics', [
                'song_id' => $songId->toString(),
            ]);

            return null;
        }

        $album = $this->albumPort->findByUuid($song->getAlbumId());
        $albumName = $album?->getTitle() ?? '';

        // Cached-first strategy
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

        if ($result === null) {
            $this->logger->info('No lyrics found on LRCLIB', [
                'song_id' => $songId->toString(),
                'title' => $song->getTitle(),
                'artist' => $artistName,
            ]);

            return null;
        }

        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: $result->plainLyrics ?? '',
            source: 'lrclib',
            sourceUrl: null,
            isInstrumental: $result->instrumental,
            syncedLyrics: $result->syncedLyrics,
            lrclibId: $result->id,
        );

        $this->lyricsRepository->save($lyrics);

        $this->logger->info('Stored lyrics from LRCLIB', [
            'song_id' => $songId->toString(),
            'lrclib_id' => $result->id,
        ]);

        return $lyrics;
    }

    public function searchLrclib(string $query): array
    {
        $this->logger->debug('Searching LRCLIB', [
            'query' => $query,
        ]);

        return $this->lrclibClient->search($query);
    }

    public function applySearchResult(int $lrclibResultId, Uuid $songId): ?Lyrics
    {
        $this->logger->debug('Applying LRCLIB search result to song', [
            'lrclib_id' => $lrclibResultId,
            'song_id' => $songId->toString(),
        ]);

        // Check if lyrics already exist for this song
        $existing = $this->lyricsRepository->findBySongId($songId);
        if ($existing !== null) {
            $this->logger->debug('Lyrics already exist for song', [
                'song_id' => $songId->toString(),
            ]);

            return $existing;
        }

        $result = $this->lrclibClient->getById($lrclibResultId);
        if ($result === null) {
            $this->logger->warning('LRCLIB result not found', [
                'lrclib_id' => $lrclibResultId,
            ]);

            return null;
        }

        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: $result->plainLyrics ?? '',
            source: 'lrclib',
            sourceUrl: null,
            isInstrumental: $result->instrumental,
            syncedLyrics: $result->syncedLyrics,
            lrclibId: $result->id,
        );

        $this->lyricsRepository->save($lyrics);

        $this->logger->info('Applied LRCLIB search result to song', [
            'song_id' => $songId->toString(),
            'lrclib_id' => $result->id,
        ]);

        return $lyrics;
    }
}
