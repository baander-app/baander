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
use App\Library\Application\Message\FilesDiscovered;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class FilesDiscoveredHandler
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly GenrePortInterface $genreService,
        private readonly SongPortInterface $songService,
        private readonly MoviePortInterface $movieService,
        private readonly VideoRepositoryInterface $videoRepository,
        private readonly MetadataContentReaderPortInterface $metadataReader,
        private readonly FFprobeAdapter $ffprobeAdapter,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(FilesDiscovered $message): void
    {
        match ($message->libraryType) {
            'music' => $this->processMusicFiles($message),
            'movie' => $this->processMovieFiles($message),
            default => throw new RuntimeException(sprintf('Unknown library type: %s', $message->libraryType)),
        };
    }

    private function processMusicFiles(FilesDiscovered $message): void
    {
        $libraryId = $message->libraryId;
        $batchCount = 0;

        // Resolve album from directory
        [$album, $wasCreated] = $this->resolveAlbum($libraryId, $message->directory, $message->files);
        if ($album === null) {
            return;
        }

        foreach ($message->files as $file) {
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
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to process file', [
                    'path' => $file->absolutePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->songService->flush();
        $this->genreService->flush();
    }

    private function processMovieFiles(FilesDiscovered $message): void
    {
        $libraryId = $message->libraryId;
        $movieTitle = basename($message->directory);

        [$movie, $wasCreated] = $this->resolveMovie($libraryId, $movieTitle);
        if ($movie === null) {
            return;
        }

        $batchCount = 0;
        foreach ($message->files as $file) {
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
                $this->logger->warning('Failed to process video file', [
                    'file' => $file->absolutePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->movieService->flush();
    }

    /**
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

            // Dispatch cover extraction for new album
            $this->dispatchCoverExtraction($album);

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

    private function dispatchCoverExtraction(Album $album): void
    {
        try {
            $this->messageBus->dispatch(
                new \App\Metadata\Application\Command\ExtractAlbumCoverCommand($album->getId()),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to dispatch cover extraction', [
                'album_id' => $album->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
