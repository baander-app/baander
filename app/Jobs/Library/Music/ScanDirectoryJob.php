<?php

namespace App\Jobs\Library\Music;

use App\Extensions\StrExt;
use App\Jobs\BaseJob;
use App\Models\{Album, Artist, Genre, Library, Song};
use App\Modules\Lyrics\Lrc;
use App\Modules\MediaMeta\MediaMeta;
use App\Modules\Translation\LocaleString;
use Arr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\{DB, File, Log};
use Illuminate\Support\LazyCollection;
use SplFileInfo;

class ScanDirectoryJob extends BaseJob implements ShouldQueue
{
    public const string ARTIST_SEPARATOR = ';';
    public const string GENRE_SEPARATOR = ';';
    private const int BATCH_SIZE = 50;

    public string $logChannel = 'music';

    public function __construct(
        public string  $directory,
        public Library $library,
    )
    {
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $sha = hash('sha256', $this->directory);
        return [new WithoutOverlapping("scan_music_directory_$sha")->dontRelease()];
    }

    /**
     * @throws \Throwable
     */
    public function handle(): void
    {
        $this->queueProgress(0);

        DB::transaction(function () {
            $files = LazyCollection::make(File::files($this->directory));
            $this->processFiles($files);
        });

        unset($this->directory, $this->library, $this->tagger);
    }

    private function processFiles(LazyCollection $files): void
    {
        $coverJobs = [];
        $songs = [];
        $processedFiles = 0;
        $fileCount = $files->count();

        $files->each(function (SplFileInfo $file) use (&$songs, &$coverJobs, &$lyrics, &$processedFiles, &$fileCount) {
            $mediaMeta = new MediaMeta($file->getRealPath());
            $this->processFile($mediaMeta, $file, $songs, $coverJobs);

            if (count($songs) >= self::BATCH_SIZE) {
                $this->batchSaveSongs($songs);
                $songs = [];
                $this->queueProgressChunk($fileCount, self::BATCH_SIZE);
            }
        });

        if (!empty($songs)) {
            $this->batchSaveSongs($songs);
        }

        $this->queueProgress(100);
        $this->queueData(['processedFiles' => $processedFiles, 'fileCount' => $fileCount]);
        $this->delete();
    }


    private function batchSaveSongs(array $songs): void
    {
        foreach ($songs as $songData) {
            $song = new Song($songData['attributes']);
            $song->album()->associate($songData['album']);

            try {
                $song->saveOrFail();
                $song->artists()->sync($this->getArtistIds($songData['artists']));
                $song->genres()->sync($this->getGenreIds($songData['genres']));
            } catch (\Throwable $e) {
                $this->logger()->error("Failed to save song: $song->title", [
                    'exception' => $e,
                ]);
            }
        }
    }

    private function processFile(MediaMeta $mediaMeta, SplFileInfo $file, array &$songs, array &$coverJobs): void
    {
        try {
            $filePath = $file->getRealPath();

            $hash = hash('sha256', $filePath);

            if (!$mediaMeta->isAudioFile() || Song::whereHash($hash)->exists()) {
                return;
            }

            if ($songData = $this->processMetadata(mediaMeta: $mediaMeta, filePath: $filePath, hash: $hash, file: $file, coverJobs: $coverJobs)) {
                $songs[] = $songData;
            }
        } catch (\Exception $e) {
            $this->logger()->error("Failed to process file: {$file->getRealPath()}", [
                'isReadable' => $file->isReadable(),
                'isFile'     => $file->isFile(),
                'exception'  => $e,
            ]);
        }
    }

    private function processMetadata(MediaMeta $mediaMeta, string $filePath, string $hash, SplFileInfo $file, array &$coverJobs): ?array
    {
        try {
            $directoryName = basename(File::basename($file));
            $album = $this->findOrCreateAlbum(directoryName: $directoryName, albumTitle: $mediaMeta->getAlbum(), year: $mediaMeta->getYear());

            if (!$album) {
                return null;
            }

            $songAttributes = $this->makeSongAttributes(mediaMeta: $mediaMeta, file: $file, hash: $hash, lyric: $this->getLyric($file));
            if ($songAttributes) {
                $this->queueCoverJob($album, $coverJobs);

                $cleanBadNames = fn($v) => trim($v) !== '' && $v !== null;

                $artists = $mediaMeta->getArtist();
                $artists = is_array($artists) ? array_filter($mediaMeta->getArtist(), $cleanBadNames) : array_filter(explode(self::ARTIST_SEPARATOR, $artists ?? ''), $cleanBadNames);
                $artistIds = $this->getArtistIds($artists);
                $album->artists()->sync($artistIds);

                return [
                    'attributes' => $songAttributes,
                    'album'      => $album,
                    'artists'    => $artists,
                    'genres'     => array_filter(explode(self::GENRE_SEPARATOR, $mediaMeta->getGenre() ?? ''), $cleanBadNames),
                ];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger()->error("Error processing metadata for file: $filePath", [
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
        $fallback = $this->isSongInBaseDirectory($directoryName) ? LocaleString::delimitString('library.album.fallback') : $directoryName;

        if (!$album) {
            $album = new Album([
                'title' => $title ?: $fallback,
                'year'  => $year,
            ]);
            $album->library()->associate($this->library);

            try {
                $album->saveOrFail();
            } catch (\Exception $e) {
                $this->logger()->error("Failed to save album: $title", [
                    'exception' => $e,
                ]);
                return null;
            }
        }

        return $album;
    }

    private function makeSongAttributes(MediaMeta $mediaMeta, SplFileInfo $file, string $hash, ?string $lyric): ?array
    {
        $mimeType = $mediaMeta->getMimeType();

        if (!$mimeType) {
            return null;
        }

        return [
            'title'         => $mediaMeta->getTitle() ?? $file->getBasename(),
            'track'         => $mediaMeta->getTrackNumber(),
            'length'        => $mediaMeta->probeLength(),
            'lyrics'        => $lyric ? StrExt::convertToUtf8($lyric) : null,
            'path'          => $file->getRealPath(),
            'mime_type'     => $mediaMeta->getMimeType(),
            'modified_time' => $file->getMTime(),
            'size'          => is_int($file->getSize()) ? $file->getSize() : 0,
            'hash'          => $hash,
        ];
    }

    private function getArtistIds(array $artists): array
    {
        return array_map(function ($artistName) {
            return Artist::firstOrCreate(['name' => trim($artistName)])->id;
        }, $artists);
    }

    private function getGenreIds(array $genres): array
    {
        return array_map(function ($genreName) {
            return Genre::firstOrCreate(['name' => ucfirst(trim($genreName))])->id;
        }, $genres);
    }

    private function queueCoverJob(Album $album, array &$coverJobs): void
    {
        if (!in_array($album->id, $coverJobs)
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