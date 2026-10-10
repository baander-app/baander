<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumType;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class FilesDiscoveredHandler
{
    private const int BATCH_SIZE = 50;

    /** How long an import waits before asking again whether a delete with files still holds its library. */
    private const int HELD_LIBRARY_RETRY_DELAY_MS = 30_000;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly GenrePortInterface $genreService,
        private readonly SongPortInterface $songService,
        private readonly MoviePortInterface $movieService,
        private readonly VideoRepositoryInterface $videoRepository,
        private readonly MetadataContentReaderPortInterface $metadataReader,
        private readonly FFprobeAdapter $ffprobeAdapter,
        private readonly MessageBusInterface $messageBus,
        private readonly LyricsFetchRequestInterface $lyricsFetch,
        private readonly AlbumMetadataSyncRequestInterface $albumMetadataSync,
        private readonly LoggerInterface $logger,
        private readonly LibraryMediaFilesInterface $mediaFiles,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(FilesDiscovered $message): void
    {
        // A delete with files that holds the library may be about to unlink some of these files,
        // and a song imported from one now would stay in the catalog without its file. Wait for
        // the delete to end.
        if ($this->mediaFiles->isHeldByDelete($message->libraryId)) {
            $this->requeueWhileHeld($message);

            return;
        }

        // Files deleted since the scan found them become no song, video or album.
        $files = $this->presentFiles($message->files);
        if ($files === []) {
            return;
        }

        match ($message->libraryType) {
            'music' => $this->processMusicFiles($message, $files),
            'movie' => $this->processMovieFiles($message, $files),
            default => throw new RuntimeException(sprintf('Unknown library type: %s', $message->libraryType)),
        };
    }

    /** @param list<DiscoveredFile> $files the message's files that still exist */
    private function processMusicFiles(FilesDiscovered $message, array $files): void
    {
        $libraryId = $message->libraryId;
        $batchCount = 0;
        $failures = [];
        // New songs without sidecar lyrics, waiting for the flush that commits them.
        $songsAwaitingLyrics = [];

        // Resolve album from directory
        [$album, $wasCreated] = $this->resolveAlbum($libraryId, $message->directory, $files);
        if ($album === null) {
            return;
        }
        // resolveAlbum() has committed a new album. A retry or rescan finds it
        // and creates none, so request its sync now rather than after the songs.
        if ($wasCreated) {
            $this->requestAlbumSync($album);
        }

        foreach ($files as $file) {
            if (!$file->isAudio()) {
                continue;
            }

            try {
                // Dedup by hash
                $existing = $this->songService->findByHash($file->hash);
                if ($existing !== null) {
                    continue;
                }

                $metadata = $this->metadataReader->readMetadata($file->absolutePath);
                if ($metadata === null) {
                    $this->logger->warning('No metadata for file', ['path' => $file->absolutePath]);
                    continue;
                }

                $title = $metadata->getTitle() ?? pathinfo($file->absolutePath, PATHINFO_FILENAME);
                if (trim($title) === '') {
                    $title = pathinfo($file->absolutePath, PATHINFO_FILENAME);
                }

                $track = $metadata->getTrackNumber() ?? $this->extractTrackNumberFromFilename($file->absolutePath);
                $lyrics = $this->findLyricsFile($file->absolutePath);

                $song = Song::create(
                    album: $album->getId(),
                    title: $title,
                    path: $file->absolutePath,
                    size: $file->size,
                    mimeType: $this->detectMimeType($file->extension),
                    length: $metadata->getDuration(),
                    lyrics: $lyrics,
                    track: $track,
                    disc: $metadata->getDiscNumber(),
                    year: $metadata->getYear(),
                    comment: $metadata->getComment(),
                    hash: $file->hash,
                    bitrate: $metadata->getBitrate(),
                    sampleRate: $metadata->getSampleRate(),
                    channels: $metadata->getChannels(),
                );

                $this->songService->persist($song);
                if ($lyrics === null) {
                    $songsAwaitingLyrics[] = $song->getId();
                }

                // Link artist
                $artistName = $metadata->getArtist();
                if ($artistName !== null && trim($artistName) !== '') {
                    $this->songService->linkArtistToSong($song->getId(), trim($artistName), ArtistRole::Primary->value);
                }

                // Link genres
                foreach ($metadata->getGenre() as $genreName) {
                    $genreName = trim($genreName);
                    if ($genreName === '') {
                        continue;
                    }
                    $genre = $this->genreService->findOrCreateByName($genreName);
                    $this->genreService->addSongToGenre($genre->getId(), $song->getId());
                }

                $batchCount++;
                if ($batchCount >= self::BATCH_SIZE) {
                    $this->songService->flush();
                    $this->genreService->flush();
                    $batchCount = 0;
                    $this->requestLyricsFetch($songsAwaitingLyrics);
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'path' => $file->absolutePath,
                    'error' => $e->getMessage(),
                ];

                $this->logger->warning('Failed to process file', [
                    'path' => $file->absolutePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->songService->flush();
        $this->genreService->flush();
        // A retry skips these songs as duplicates, so request their lyrics before
        // reporting failed files.
        $this->requestLyricsFetch($songsAwaitingLyrics);

        if ($failures !== []) {
            throw new RuntimeException($this->buildFailureMessage($failures));
        }

        // A consumer can run immediately after dispatch. Flush songs first, and
        // retry cover dispatch even when an earlier delivery created the album.
        if ($album->getCoverImageId() === null) {
            $this->dispatchCoverExtraction($album);
        }
    }

    /** @param list<DiscoveredFile> $files the message's files that still exist */
    private function processMovieFiles(FilesDiscovered $message, array $files): void
    {
        $libraryId = $message->libraryId;
        $movieTitle = basename($message->directory);

        [$movie, $wasCreated] = $this->resolveMovie($libraryId, $movieTitle);
        if ($movie === null) {
            return;
        }

        $batchCount = 0;
        $failures = [];
        foreach ($files as $file) {
            if (!$file->isVideo()) {
                continue;
            }

            try {
                $existing = $this->videoRepository->findByHash($file->hash);
                if ($existing !== null) {
                    if (!in_array($existing->getId()->toString(), $movie->getVideoIds(), true)) {
                        $movie->addVideo($existing->getId());
                        $this->movieService->persist($movie);
                    }
                    continue;
                }

                $probeResult = $this->ffprobeAdapter->probeVideo($file->absolutePath);

                $video = Video::create(
                    path: $file->absolutePath,
                    hash: $file->hash,
                    duration: (int) $probeResult->duration,
                    height: $probeResult->height,
                    width: $probeResult->width,
                    videoBitrate: $probeResult->videoBitrate,
                    framerate: (int) $probeResult->framerate,
                );
                $video->updateProbe($probeResult->jsonSerialize());
                $this->videoRepository->save($video);

                $movie->addVideo($video->getId());
                $this->movieService->persist($movie);

                $batchCount++;
                if ($batchCount >= self::BATCH_SIZE) {
                    $this->movieService->flush();
                    $batchCount = 0;
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'path' => $file->absolutePath,
                    'error' => $e->getMessage(),
                ];

                $this->logger->warning('Failed to process video file', [
                    'file' => $file->absolutePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->movieService->flush();

        if ($failures !== []) {
            throw new RuntimeException($this->buildFailureMessage($failures));
        }
    }

    /**
     * @param list<DiscoveredFile> $files
     * @return array{Album|null, bool}
     */
    private function resolveAlbum(Uuid $libraryId, string $directory, array $files): array
    {
        $directoryName = basename($directory);
        $metadataTitle = null;
        $metadataYear = null;
        $metadataAlbumArtist = null;

        foreach ($files as $file) {
            if (!$file->isAudio()) {
                continue;
            }
            $metadata = $this->metadataReader->readMetadata($file->absolutePath);
            if ($metadata !== null) {
                $metadataTitle = $metadata->getAlbum();
                $metadataYear = $metadata->getYear();
                $metadataAlbumArtist = $metadata->getAlbumArtist();
                break;
            }
        }

        $title = $metadataTitle ?? $directoryName;
        if (trim($title) === '' || trim($title) === '.') {
            return [null, false];
        }

        $album = $this->albumService->findByTitleAndLibrary($title, $libraryId);
        if ($album === null) {
            $album = Album::create(
                $libraryId,
                $title,
                AlbumType::Studio->value,
                year: $metadataYear,
            );
            $this->albumService->persist($album);
            $this->albumService->flush();

            if ($metadataAlbumArtist !== null && trim($metadataAlbumArtist) !== '') {
                $this->albumService->linkArtistToAlbum($album->getId(), trim($metadataAlbumArtist), ArtistRole::Primary->value);
                $this->albumService->flush();
            }

            return [$album, true];
        }

        return [$album, false];
    }

    /**
     * @return array{Movie|null, bool}
     */
    private function resolveMovie(Uuid $libraryId, string $title): array
    {
        $existing = $this->movieService->findByTitleAndLibrary($title, $libraryId);
        if ($existing !== null) {
            return [$existing, false];
        }

        $movie = Movie::create(libraryId: $libraryId, title: $title);
        $this->movieService->persist($movie);

        return [$movie, true];
    }

    /**
     * Puts the message back on its queue with a delay. The delete renews its claim while it runs
     * and the claim lapses with its lease when the delete dies, so the import runs in the end.
     */
    private function requeueWhileHeld(FilesDiscovered $message): void
    {
        $this->logger->info('A delete with files holds library {library_id}; the import of {directory} waits for it.', [
            'library_id' => $message->libraryId->toString(),
            'directory' => $message->directory,
        ]);

        $this->messageBus->dispatch($message, [
            new TransportNamesStamp(['async']),
            new DelayStamp(self::HELD_LIBRARY_RETRY_DELAY_MS),
        ]);
    }

    /**
     * @param array<DiscoveredFile> $files
     *
     * @return list<DiscoveredFile>
     */
    private function presentFiles(array $files): array
    {
        $files = array_values($files);
        $missing = $this->mediaFiles->missingPaths(array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, $files));
        if ($missing === []) {
            return $files;
        }

        $this->logger->info('Skipping {count} file(s) deleted since the scan found them.', [
            'count' => count($missing),
            'paths' => $missing,
        ]);

        return array_values(array_filter($files, static fn (DiscoveredFile $file): bool => !in_array($file->absolutePath, $missing, true)));
    }

    private function detectMimeType(string $extension): string
    {
        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'ogg', 'oga' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'opus' => 'audio/opus',
            'wma' => 'audio/x-ms-wma',
            default => 'application/octet-stream',
        };
    }

    private function extractTrackNumberFromFilename(string $path): ?int
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        if (preg_match('/^(\d+)\s*[-.\s]\s*(.+)$/', $filename, $matches)) {
            $trackNumber = (int) $matches[1];
            if ($trackNumber >= 1 && $trackNumber <= 99) {
                return $trackNumber;
            }
        }
        return null;
    }

    private function findLyricsFile(string $audioPath): ?string
    {
        $lyricPath = pathinfo($audioPath, PATHINFO_DIRNAME)
            . DIRECTORY_SEPARATOR
            . pathinfo($audioPath, PATHINFO_FILENAME)
            . '.lrc';

        if (file_exists($lyricPath) && is_readable($lyricPath)) {
            return file_get_contents($lyricPath);
        }
        return null;
    }

    /**
     * @param array<int, array{path: string, error: string}> $failures
     */
    private function buildFailureMessage(array $failures): string
    {
        $details = array_map(
            static fn (array $failure): string => sprintf('%s: %s', $failure['path'], $failure['error']),
            $failures,
        );

        return sprintf('Failed to process %d file(s): %s', count($failures), implode('; ', $details));
    }

    /**
     * Requests a metadata sync for an album that resolveAlbum() has just committed.
     *
     * The request is best-effort: a failure must not fail the ingest, whose retry
     * would find the album and request nothing. A library metadata sync recovers it.
     */
    private function requestAlbumSync(Album $album): void
    {
        try {
            $this->albumMetadataSync->requestSync($album->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Could not request a metadata sync for new album {album_id}; a library metadata sync from the admin panel picks it up.', [
                'album_id' => $album->getId()->toString(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Requests lyrics for songs that a flush has just committed, then forgets them.
     *
     * The request is best-effort: a failure must not fail the ingest, whose retry
     * would skip these songs as duplicates. Failed songs stay pending, so the next
     * flush asks again; after the last flush the bulk lyrics fetch recovers them.
     *
     * @param list<Uuid> $songIds
     */
    private function requestLyricsFetch(array &$songIds): void
    {
        if ($songIds === []) {
            return;
        }

        try {
            $this->lyricsFetch->requestFetch(...$songIds);
        } catch (\Throwable $e) {
            $this->logger->error('Could not request lyrics for {count} new song(s); they stay pending until the next flush.', [
                'count' => count($songIds),
                'song_ids' => array_map(static fn (Uuid $id): string => $id->toString(), $songIds),
                'exception' => $e,
            ]);

            return;
        }

        $songIds = [];
    }

    private function dispatchCoverExtraction(Album $album): void
    {
        $this->messageBus->dispatch(
            new \App\Metadata\Application\Command\ExtractAlbumCoverCommand($album->getId()),
        );
    }
}
