<?php

namespace App\Jobs\Library\Music;

use App\Modules\Translation\LocaleString;
use Arr;
use SplFileInfo;
use App\Extensions\StrExt;
use App\Jobs\BaseJob;
use App\Models\{Album, Artist, Genre, Library, Song};
use App\Modules\Lyrics\Lrc;
use App\Modules\MetaAudio\{MetaAudio, Mp3, Tagger};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\{DB, File, Log};
use Illuminate\Support\LazyCollection;

class ScanDirectoryJob extends BaseJob implements ShouldQueue
{
    public const string ARTIST_SEPARATOR = ';';
    public const string GENRE_SEPARATOR = ';';
    private const int BATCH_SIZE = 50;

    private Tagger $tagger;

    public function __construct(
        public string  $directory,
        public Library $library,
    )
    {
    }

    public function handle(): void
    {
        $this->queueProgress(0);

        DB::transaction(function () {
            $this->tagger = new Tagger();
            $this->tagger->addDefaultModules();

            $files = LazyCollection::make(File::files($this->directory));
            $this->processFiles($files);
        });

        unset($this->directory, $this->library, $this->tagger);
    }

    private function processFiles(LazyCollection $files): void
    {
        $lyrics = [];
        $coverJobs = [];
        $songs = [];
        $processedFiles = 0;
        $fileCount = $files->count();

        $files->each(function (SplFileInfo $file) use (&$songs, &$coverJobs, &$lyrics, &$processedFiles, &$fileCount) {
            $this->processFile($file, $songs, $coverJobs, $lyrics);

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
                Log::error("Failed to save song: $song->title", [
                    'exception' => $e,
                ]);
            }
        }
    }

    private function processFile(SplFileInfo $file, array &$songs, array &$coverJobs, array &$lyrics): void
    {
        try {
            $filePath = $file->getRealPath();

            if ($file->getExtension() === Lrc::FILE_EXTENSION) {
                $lyrics[$filePath] = $file;
                return;
            }

            $hash = sha1_file($filePath);
            $metaAudio = new MetaAudio($file);

            if (!$metaAudio->isAudioFile() || Song::whereHash($hash)->exists()) {
                return;
            }

            if ($songData = $this->processMetadata(metaAudio: $metaAudio, filePath: $filePath, hash: $hash, file: $file, lyrics: $lyrics, coverJobs: $coverJobs)) {
                $songs[] = $songData;
            }
        } catch (\Exception $e) {
            Log::error("Failed to process file: {$file->getRealPath()}", [
                'exception' => $e,
            ]);
        }
    }

    private function processMetadata(MetaAudio $metaAudio, string $filePath, string $hash, SplFileInfo $file, array &$lyrics, array &$coverJobs): ?array
    {
        try {
            $meta = $this->tagger->open($filePath);
            $directoryName = basename(File::basename($file));
            $album = $this->findOrCreateAlbum(meta: $meta, directoryName: $directoryName);

            if (!$album) {
                return null;
            }

            $songAttributes = $this->makeSongAttributes(meta: $meta, file: $file, metaAudio: $metaAudio, hash: $hash, lyric: $this->getLyric($file, $lyrics));
            if ($songAttributes) {
                $this->queueCoverJob($album, $coverJobs);

                $cleanBadNames = fn($v) => trim($v) !== '' && $v !== null;

                return [
                    'attributes' => $songAttributes,
                    'album'      => $album,
                    'artists'    => array_filter(explode(self::ARTIST_SEPARATOR, $meta->getArtist() ?? ''), $cleanBadNames),
                    'genres'     => array_filter(explode(self::GENRE_SEPARATOR, $meta->getGenre()), $cleanBadNames),
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error processing metadata for file: $filePath", [
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function getLyric(SplFileInfo $file, array $lyrics): ?string
    {
        $lyricPath = pathinfo($file->getRealPath(), PATHINFO_FILENAME) . '.' . Lrc::FILE_EXTENSION;

        return $lyrics[$lyricPath] ?? (File::exists($lyricPath) ? File::get($lyricPath) : null);
    }

    private function findOrCreateAlbum(Mp3 $meta, string $directoryName): ?Album
    {
        $title = $meta->getAlbumTitle() ?? $meta->getAlbum();
        $album = Album::whereTitle($title)->whereLibraryId($this->library->id)->first();
        $fallback = $this->isSongInBaseDirectory($directoryName) ? LocaleString::delimitString('library.album.fallback') : $directoryName;

        if (!$album) {
            $album = new Album([
                'title' => $title ?: $fallback,
                'year'  => $meta->getYear() ?: null,
            ]);
            $album->library()->associate($this->library);

            try {
                $album->saveOrFail();
            } catch (\Exception $e) {
                Log::error("Failed to save album: $title", [
                    'exception' => $e,
                ]);
                return null;
            }
        }

        return $album;
    }

    private function makeSongAttributes(Mp3 $meta, SplFileInfo $file, MetaAudio $metaAudio, string $hash, ?string $lyric): ?array
    {
        $mimeType = $metaAudio->mimeType();

        if (!$mimeType) {
            return null;
        }

        return [
            'title'         => $meta->getTitle() ?? $file->getBasename(),
            'track'         => $meta->getTrackNumber(),
            'length'        => $metaAudio->probeLength(),
            'lyrics'        => StrExt::convertToUtf8($lyric),
            'path'          => $file->getRealPath(),
            'mime_type'     => $metaAudio->mimeType(),
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
            dispatch(new SaveAlbumCoverJob($album))->afterCommit();
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