<?php

namespace App\Jobs\Library\Music;

use App\Primitives\Text;
use App\Jobs\BaseJob;
use App\Models\{Album, AlbumRole, Artist, Genre, Library, Song};
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Lyrics\Lrc;
use App\Modules\Metadata\Readers\MetadataReader;
use App\Modules\Security\MagicByteValidator;
use App\Modules\Security\Exceptions\FileValidationException;
use App\Services\Metadata\MetadataDelimiterService;
use App\Format\LocaleString;
use App\Primitives\Sequence;
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

    private array $securityConfig;

    private MagicByteValidator $magicByteValidator;

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
        $this->securityConfig = config('scanner.security', []);
        $this->magicByteValidator = app(MagicByteValidator::class);
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
        $maxTotalFiles = $this->securityConfig['max_total_files'] ?? 100000;

        $files->each(function (SplFileInfo $file) use (&$songs, &$coverJobs, &$lyrics, &$processedFiles, &$fileCount, $maxTotalFiles) {
            // Check total file limit
            if ($processedFiles >= $maxTotalFiles) {
                $this->getLogger()->warning('Maximum file limit reached', [
                    'limit' => $maxTotalFiles,
                ]);
                return false; // Stop processing
            }

            $metadataReader = new MetadataReader($file->getRealPath());
            $this->processFile($metadataReader, $file, $songs, $coverJobs);

            if (count($songs) >= self::BATCH_SIZE) {
                $this->batchSaveSongs($songs);
                $songs = [];
                $this->queueProgressChunk($fileCount, self::BATCH_SIZE);
            }

            $processedFiles++;
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

            // Security: Check file size
            if (!$this->validateFileSize($file, $filePath)) {
                return;
            }

            // Security: Validate magic bytes if enabled
            if ($this->shouldValidateMagicBytes()) {
                if (!$this->magicByteValidator->isValidAudioFile($filePath)) {
                    $this->getLogger()->warning('File failed magic byte validation', [
                        'path' => $filePath,
                    ]);
                    return;
                }

                // Security: Check MIME consistency if enabled
                if (!$this->allowMimeMismatch()) {
                    $declaredMime = $metadataReader->getMimeType();
                    if (!$this->magicByteValidator->validateAgainstMime($filePath, $declaredMime)) {
                        $this->getLogger()->warning('MIME type mismatch detected', [
                            'path' => $filePath,
                            'declared_mime' => $declaredMime,
                        ]);
                        return;
                    }
                }
            }

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
                $rawArtists = $delimiterService->splitArtists($metadataReader->getArtist());

                // Sanitize artist names if enabled
                $artists = $this->sanitizeMetadata()
                    ? $this->sanitizeArrayOfStrings($rawArtists, 'artist')
                    : $rawArtists;

                // Split genres with smart detection
                $rawGenres = $delimiterService->splitGenres($metadataReader->getGenre());

                // Sanitize genre names if enabled
                $genres = $this->sanitizeMetadata()
                    ? $this->sanitizeArrayOfStrings($rawGenres, 'genre')
                    : $rawGenres;

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

        // Get raw metadata values
        $rawTitle = $metadataReader->getTitle() ?? $file->getBasename() ?? LocaleString::delimit('library.song.unknown');
        $rawComment = $metadataReader->getComment();

        // Sanitize metadata if enabled
        $title = $this->sanitizeMetadata() ? Text::sanitizeMetadata($rawTitle) : $rawTitle;
        $comment = $rawComment !== null && $this->sanitizeMetadata() ? Text::sanitize($rawComment) : $rawComment;

        // Truncate to max length
        $maxLength = $this->getMaxMetadataLength('title');
        $title = mb_substr($title, 0, $maxLength);

        // Sanitize lyrics separately (preserve formatting)
        $lyrics = $lyric ? ($this->sanitizeMetadata() ? Text::sanitizeLyrics($lyric) : Text::convertToUtf8($lyric)) : null;

        return [
            'title'     => $title,
            'track'     => $trackNumber,
            'length'    => $metadataReader->probeLength(),
            'lyrics'    => $lyrics,
            'path'      => $file->getRealPath(),
            'mime_type' => $metadataReader->getMimeType(),
            'size'      => is_int($file->getSize()) ? $file->getSize() : 0,
            'hash'      => $hash,
            'comment'   => $comment,
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
        return Sequence::has($coverJobs, $album->id);
    }

    private function isSongInBaseDirectory(string $path): bool
    {
        $baseDirectory = $this->library->path;
        $relativePath = str_replace($baseDirectory, '', $path);

        return $relativePath[0] !== DIRECTORY_SEPARATOR;
    }

    private function validateFileSize(SplFileInfo $file, string $filePath): bool
    {
        $size = $file->getSize();

        if ($size === false) {
            return true;
        }

        // Convert MB limit to bytes
        $maxSizeMb = $this->securityConfig['max_file_size_mb']['audio'] ?? 500;
        $maxSizeBytes = $maxSizeMb * 1024 * 1024;

        if ($size > $maxSizeBytes) {
            $this->getLogger()->warning('File exceeds maximum size', [
                'path' => $filePath,
                'size' => $size,
                'max_size' => $maxSizeBytes,
            ]);
            return false;
        }

        return true;
    }

    private function shouldValidateMagicBytes(): bool
    {
        return $this->securityConfig['validate_magic_bytes'] ?? true;
    }

    private function allowMimeMismatch(): bool
    {
        return $this->securityConfig['allow_mime_mismatch'] ?? false;
    }

    private function sanitizeMetadata(): bool
    {
        return $this->securityConfig['sanitize_metadata'] ?? true;
    }

    private function getMaxMetadataLength(string $field): int
    {
        return $this->securityConfig['max_metadata_length'][$field] ?? 255;
    }

    private function sanitizeArrayOfStrings(array $strings, string $field): array
    {
        $maxLength = $this->getMaxMetadataLength($field);
        $sanitized = [];

        foreach ($strings as $string) {
            $sanitizedValue = match ($field) {
                'artist' => Text::sanitizeMetadata($string),
                'genre' => Text::sanitizeMetadata($string),
                default => Text::sanitize($string),
            };

            $sanitized[] = mb_substr($sanitizedValue, 0, $maxLength);
        }

        return array_filter($sanitized, fn($s) => !empty($s));
    }
}