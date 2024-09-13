<?php

namespace App\Jobs\Library;

use App\Models\{Album, Artist, Genre, Library, Song};
use App\Events\LibraryScanCompleted;
use App\Jobs\Concerns\HasJobsLogger;
use App\Packages\StrExt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use App\Packages\MetaAudio\{MetaAudio, Mp3, Tagger};
use App\Support\Logger\StdOutLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Safe\Exceptions\{MbstringException, StringsException};
use romanzipp\QueueMonitor\Traits\IsMonitored;

class ScanMusicLibraryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IsMonitored, HasJobsLogger;

    private Library $library;
    private Tagger $tagger;
    private StdOutLogger $logger;

    /**
     * Create a new job instance.
     */
    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->queueProgress(0);

        $this->library->update(['last_scan' => now()]);
        $path = $this->library->path;

        $directories = LazyCollection::make(File::directories($path));

        $this->tagger = new Tagger();
        $this->tagger->addDefaultModules();

        $totalDirectories = count($directories);
        $processedDirectories = 0;

        foreach ($directories as $directory) {
            $this->scanDirectory($directory);
            unset($directory);

            $processedDirectories++;
            $progress = ($processedDirectories / $totalDirectories) * 100;

            $this->queueProgress($progress);
        }

        $this->queueProgress(100);
        $this->queueData([
            'processedDirectories' => $processedDirectories,
        ]);
        LibraryScanCompleted::dispatch($this->library);

        unset($this->library, $this->tagger);
    }

    private function scanDirectory(string $directory): void
    {
        $files = LazyCollection::make(File::files($directory));
        $lyrics = [];

        $this->logger()->info('Scanning directory: ' . $directory);

        $coverJobs = [];

        $files->each(/**
         * @throws \Throwable
         * @throws MbstringException
         * @throws StringsException
         */ function (\SplFileInfo $file) use (&$coverJobs, &$lyrics) {
            $this->logger()->info('Scanning file: ' . $file->getFilename());

            if ($file->getExtension() === 'lrc') {
                $lyrics[] = $file->getRealPath();
                return;
            }

            $realPath = $file->getRealPath();
            $hash = \Safe\sha1_file($realPath);
            $metaAudio = new MetaAudio($file);

            if (!$metaAudio->isAudioFile() || Song::whereHash($hash)->exists()) {
                return;
            }

            $this->logger()->info('Processing file: ' . $file->getFilename());

            $meta = $this->tagger->open($realPath);

            $bandName = $meta->getBand();
            $this->logger()->info("Looking up artist: $bandName");
            $albumArtist = Artist::whereName($bandName)->firstOrCreate([
                'name' => $bandName,
            ]);

            $albumName = $meta->getAlbum();
            $this->logger()->info('Album: ' . $albumName);

            $directoryName = basename(\File::dirname($file));
            $album = Album::whereTitle($albumName)->whereDirectory($directoryName)->first();
            if (!$album) {
                $album = $this->createAlbum($meta, $albumArtist, $directoryName);
            }

            if (!$album) {
                return;
            }

            $pathInfo = pathinfo($file->getRealPath());
            $fileWithoutExtension = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $pathInfo['filename'];
            $lyricPath = $fileWithoutExtension . '.lrc';
            $lyric = null;
            if (array_key_exists($fileWithoutExtension, $lyrics)) {
                $lyric = File::get($lyricPath);
            } else if (File::exists($lyricPath)) {
                $lyric = File::get($lyricPath);
            }
            $song = $this->makeSong($meta, $file, $metaAudio, $hash, $lyric);

            if ($song) {
                $this->logger()->info("Creating song: $song->title");
                $song->album()->associate($album);

                try {
                    $song->saveOrFail();
                } catch (\Throwable $e) {
                    $this->logSaveError($e, $song);
                }
            }

            unset($lyrics[$fileWithoutExtension]);

            $this->processGenres($meta, $song);

            $artists = array_map(fn(string $artist) => $artist, \Safe\preg_split('#([;\\\/])#', $meta->getArtist()));

            $this->processArtists($artists, $song);

            if (!$album->cover()->exists() && !isset($coverJobs[$album->id])) {
                dispatch(new SaveAlbumCoverJob($album));
                $coverJobs[$album->id] = true;
            }
        });
    }

    /**
     * @throws \Throwable
     */
    private function createAlbum(Mp3 $meta, Artist $albumArtist, string $directoryName): ?Album
    {
        $albumYear = $meta->getYear();

        $album = new Album([
            'title'     => $meta->getAlbumTitle() ?? $meta->getAlbum() ?? $directoryName,
            'year'      => $albumYear !== 0 ? $albumYear : null,
            'directory' => $directoryName,
        ]);

        $album->albumArtist()->associate($albumArtist);
        $album->library()->associate($this->library);

        try {
            $album->saveOrFail();
        } catch (\Throwable $e) {
            $this->logSaveError($e, $album);
            return null;
        }

        $this->logger()->info('Created album: ' . $album->title);

        return $album;
    }

    private function makeSong(Mp3 $meta, \SplFileInfo $file, MetaAudio $metaAudio, string $hash, ?string $lyrics): ?Song
    {
        $mimeType = $metaAudio->mimeType();

        if (!$mimeType) {
            return null;
        }

        return new Song([
            'title'         => $meta->getTitle(),
            'track'         => $meta->getTrackNumber(),
            'length'        => $metaAudio->probeLength(),
            'lyrics'        => StrExt::convertToUtf8($lyrics),
            'path'          => $file->getRealPath(),
            'mime_type'     => $mimeType,
            'modified_time' => $file->getMTime(),
            'size'          => is_int($file->getSize()) ? $file->getSize() : 0,
            'hash'          => $hash,
        ]);
    }

    private function processArtists(array $artists, Song $song)
    {
        $artistIds = [];

        foreach ($artists as $artist) {
            $artistModel = Artist::whereName($artist)->first();

            if (!$artistModel && Str::length($artist) > 0) {
                $artistModel = Artist::create([
                    'name' => $artist,
                ]);

                try {
                    $artistModel->saveOrFail();
                } catch (\Throwable $e) {
                    $this->logSaveError($e, $artistModel);
                }
            }

            if ($artistModel->id) {
                $artistIds[] = $artistModel->id;
            }
        }

        $song->artists()->sync($artistIds);
    }

    private function processGenres(Mp3 $mp3, Song $song)
    {
        $genreIds = [];
        $genres = $mp3->getGenre();
        if (!is_string($genres)) {
            return;
        }

        $genres = collect(explode(';', $genres))->each(fn(string $genre) => Str::ucfirst(trim($genre)));

        foreach ($genres as $genre) {
            $genreModel = Genre::whereName($genre)->first();

            if (!$genreModel) {
                $genreModel = new Genre([
                    'name' => $genre,
                ]);
            }

            try {
                $genreModel->saveOrFail();
            } catch (\Throwable $e) {
                $this->logSaveError($e, $genreModel);
            }

            if ($genreModel->id) {
                $genreIds[] = $genreModel->id;
            }
        }

        $song->genres()->sync($genreIds);
    }

    private function logSaveError(\Throwable $e, Model $model)
    {
        $modelName = get_class($model);

        $this->logger()->error("Error saving $modelName: ", [
            'exception' => $e,
            'sql'       => $model->dumpRawSql(),
        ]);
    }
}
