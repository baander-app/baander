<?php

namespace App\Jobs\Library\Music;

use App\Extensions\StrExt;
use App\Jobs\BaseJob;
use App\Models\{Album, AlbumRole, Artist, Genre, Library, Song};
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Lyrics\Lrc;
use App\Modules\Metadata\Readers\MetadataReader;
use App\Services\Metadata\MetadataDelimiterService;
use App\Format\LocaleString;
use Arr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\{DB, File};
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Throwable;

class ScanDirectoryJob extends BaseJob implements ShouldQueue
{
    private const int BATCH_SIZE = 50;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    /**
     * Default options for delimiter detection.
     */
    private const array DEFAULT_OPTIONS = [
        'smart_detection' => true,
        'artist_delimiters' => [';', '/', '&'],
        'genre_delimiters' => [';', '/'],
    ];

    public function __construct(
        private string $directory,
        private Library $library,
        private array $options = self::DEFAULT_OPTIONS,
    ) {
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $hash = hash('xxh3', $this->directory);
        return [
            new WithoutOverlapping("scan_music_directory_$hash"),
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $this->queueProgress(0);

        DB::transaction(function () {
            $files = LazyCollection::make(File::files($this->directory));
            $this->processFiles($files);
        });
    }

    private function processFiles(LazyCollection $files): void
    {
        $this->getLogger()->info('Processing files in ' . $this->directory, [
            'files' => $files->count(),
        ]);

        $coverJobs = [];
        $songs = [];
        $processedFiles = 0;
        $fileCount = $files->count();

        $files->each(function (SplFileInfo $file) use (&$songs, &$coverJobs, &$lyrics, &$processedFiles, &$fileCount) {
            $metadataReader = new MetadataReader($file->getRealPath());
            $this->processFile($metadataReader, $file, $songs, $coverJobs);

            if (count($songs) >= self::BATCH_SIZE) {
                $this->batchSaveSongs($songs);
                $songs = [];
                $this->queueProgressChunk($fileCount, self::BATCH_SIZE);
            }
        });


        $this->getLogger()->info('Saving remaining songs');

        if (!empty($songs)) {
            $this->batchSaveSongs($songs);
        }

        $this->queueProgress(100);
        $this->queueData(['processedFiles' => $processedFiles, 'fileCount' => $fileCount]);
    }


    private function batchSaveSongs(array $songs): void
    {
        foreach ($songs as $songData) {
            $song = new Song($songData['attributes']);
            $song->album()->associate($songData['album']);

            try {
                $song->saveOrFail();
                $artistIds = $this->getArtistIds($songData['artists']);

                // Sync artists without role first
                $song->artists()->sync($artistIds);

                // Then set the first artist as Primary if artists exist
                if (!empty($artistIds)) {
                    $song->artists()->updateExistingPivot($artistIds[0], ['role' => AlbumRole::Primary->value]);
                }
                $song->genres()->sync($this->getGenreIds($songData['genres']));
            } catch (Throwable $e) {
                $this->getLogger()->error("Failed to save song: $song->title", [
                    'exception' => $e,
                ]);
            }
        }
    }

    private function processFile(MetadataReader $metadataReader, SplFileInfo $file, array &$songs, array &$coverJobs): void
    {
        try {
            $filePath = $file->getRealPath();

            $hash = hash('xxh3', $filePath);

            if (!$metadataReader->isAudioFile() || Song::whereHash($hash)->exists()) {
                return;
            }

            if ($songData = $this->processMetadata(metadataReader: $metadataReader, filePath: $filePath, hash: $hash, file: $file, coverJobs: $coverJobs)) {
                $songs[] = $songData;
            }
        } catch (\Exception $e) {
            $this->getLogger()->error("Failed to process file: {$file->getRealPath()}", [
                'isReadable' => $file->isReadable(),
                'isFile'     => $file->isFile(),
                'exception'  => $e,
            ]);
        }
    }

    private function processMetadata(MetadataReader $metadataReader, string $filePath, string $hash, SplFileInfo $file, array &$coverJobs): ?array
    {
        $this->getLogger()->info("Processing metadata for file: $filePath");

        try {
            $directoryName = basename(File::basename($file));
            $year = $metadataReader->getYear() ? Carbon::parse($metadataReader->getYear())->format('Y') : null;
            $album = $this->findOrCreateAlbum(directoryName: $directoryName, albumTitle: $metadataReader->getAlbum(), year: $year);

            if (!$album) {
                $this->getLogger()->error("Failed to find or create album for file: $filePath");

                return null;
            }

            $songAttributes = $this->makeSongAttributes(metadataReader: $metadataReader, file: $file, hash: $hash, lyric: $this->getLyric($file));
            if ($songAttributes) {
                $this->queueCoverJob($album, $coverJobs);

                $delimiterService = new MetadataDelimiterService($this->options);

                // Split artists with smart detection
                $artists = $delimiterService->splitArtists($metadataReader->getArtist());

                // Split genres with smart detection
                $genres = $delimiterService->splitGenres($metadataReader->getGenre());

                return [
                    'attributes' => $songAttributes,
                    'album'      => $album,
                    'artists'    => $artists,
                    'genres'     => $genres,
                ];
            }

            return null;
        } catch (\Exception $e) {
            $this->getLogger()->error("Error processing metadata for file: $filePath", [
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function getLyric(SplFileInfo $file): ?string
    {
        $fullPath = $file->getRealPath();
        if (!$fullPath) {
            return null;
        }

        $pathWithoutFileName = pathinfo($fullPath, PATHINFO_DIRNAME);
        $lyricPath = $pathWithoutFileName . DIRECTORY_SEPARATOR . pathinfo($fullPath, PATHINFO_FILENAME) . '.' . Lrc::FILE_EXTENSION;

        return File::exists($lyricPath) ? File::get($lyricPath) : null;
    }

    private function findOrCreateAlbum(string $directoryName, string|null $albumTitle = null, int|null $year = null): ?Album
    {
        $title = $albumTitle;
        $album = Album::whereTitle($title)->whereLibraryId($this->library->id)->first();
        $fallback = $this->isSongInBaseDirectory($directoryName) ? LocaleString::delimit('library.album.unknown') : $directoryName;

        if (!$album) {
            $album = new Album([
                'title' => $title ?: $fallback,
                'year'  => $year,
            ]);
            $album->library()->associate($this->library);

            try {
                $album->saveOrFail();
            } catch (\Exception|\Throwable $e) {
                $this->getLogger()->error("Failed to save album: $title", [
                    'exception' => $e,
                ]);
                return null;
            }
        }

        return $album;
    }

    private function makeSongAttributes(MetadataReader $metadataReader, SplFileInfo $file, string $hash, ?string $lyric): ?array
    {
        $mimeType = $metadataReader->getMimeType();

        if (!$mimeType) {
            $this->getLogger()->error("Failed to get mime type for file: $file");

            return null;
        }

        $trackNumber = $metadataReader->getTrackNumber();

        // Fallback: extract track number from filename if not available in metadata
        if ($trackNumber === null) {
            $trackNumber = $this->extractTrackNumberFromFilename($file->getBasename());
        }

        return [
            'title'     => $metadataReader->getTitle() ?? $file->getBasename() ?? LocaleString::delimit('library.song.unknown'),
            'track'     => $trackNumber,
            'length'    => $metadataReader->probeLength(),
            'lyrics'    => $lyric ? StrExt::convertToUtf8($lyric) : null,
            'path'      => $file->getRealPath(),
            'mime_type' => $metadataReader->getMimeType(),
            'size'      => is_int($file->getSize()) ? $file->getSize() : 0,
            'hash'      => $hash,
            'comment'   => $metadataReader->getComment(),
        ];
    }

    /**
     * Extract track number from filename
     *
     * Handles formats like:
     * - "03 - title.mp3" -> 3
     * - "03-title.mp3" -> 3
     * - "03 title.mp3" -> 3
     *
     * @param string $filename The filename (without extension)
     * @return int|null The track number or null if not found
     */
    private function extractTrackNumberFromFilename(string $filename): ?int
    {
        // Remove file extension
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        // Match patterns like "03 - title", "03-title", "03 title", etc.
        // Pattern: number at start, followed by space, dash, or dot
        if (preg_match('/^(\d+)\s*[-.\s]\s*(.+)$/', $filenameWithoutExt, $matches)) {
            $trackNumber = (int) $matches[1];

            // Sanity check: track numbers are usually 1-99
            if ($trackNumber >= 1 && $trackNumber <= 99) {
                return $trackNumber;
            }
        }

        return null;
    }

    private function getArtistIds(array $artists): array
    {
        return array_map(static function ($artistName) {
            return Artist::firstOrCreate(['name' => trim($artistName)])->id;
        }, $artists);
    }

    private function getGenreIds(array $genres): array
    {
        return array_map(static function ($genreName) {
            return Genre::firstOrCreate(['name' => ucfirst(trim($genreName))])->id;
        }, $genres);
    }

    private function queueCoverJob(Album $album, array &$coverJobs): void
    {
        if (!in_array($album->id, $coverJobs, true)
            && !$album->cover()->exists()
            && !$this->isCoverJobQueued($album, $coverJobs)) {
            SaveAlbumCoverJob::dispatch($album)->afterCommit();
            $coverJobs[] = $album->id;
        }
    }

    private function isCoverJobQueued(Album $album, array $coverJobs): bool
    {
        return Arr::has($coverJobs, $album->id);
    }

    private function isSongInBaseDirectory(string $path): bool
    {
        $baseDirectory = $this->library->path;
        $relativePath = str_replace($baseDirectory, '', $path);

        return $relativePath[0] !== DIRECTORY_SEPARATOR;
    }
}