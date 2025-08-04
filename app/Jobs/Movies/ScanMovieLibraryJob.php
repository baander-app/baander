<?php

namespace App\Jobs\Movies;

use App\Jobs\BaseJob;
use App\Models\{Library, Movie, Video};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{DB, File};
use Illuminate\Support\LazyCollection;

class ScanMovieLibraryJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $movieCache = [];

    /**
     * Create a new job instance.
     */
    public function __construct(public Library $library)
    {
        //
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->library->id)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->queueProgress(0);

            $this->library->updateLastScan();
            $path = $this->library->path;

            $directories = LazyCollection::make(File::directories($path));
            \Log::channel('stdout')->info('Found ' . $directories->count() . ' directories in ' . $path);

            $totalDirectories = count($directories);
            $processedDirectories = 0;
            $chunkSize = 10;

            $directories->chunk($chunkSize)->each(function ($chunk) use (&$processedDirectories, $totalDirectories, &$chunkSize) {
                \Log::channel('stdout')->info('Processing ' . $processedDirectories . '/' . $totalDirectories . ' directories');
                foreach ($chunk as $directory) {
                    try {
                        $this->scanDirectory($directory);
                    } catch (\Exception $e) {
                        \Log::channel('stdout')->error('Failed to scan directory: ' . $directory, [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                    $processedDirectories++;
                    $this->queueProgressChunk($totalDirectories, $chunkSize);
                }
            });

            unset($this->library, $this->movieCache);
        } catch (\Exception $e) {
            \Log::channel('stdout')->error('ScanMovieLibraryJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        $this->delete();
    }


    private function scanDirectory(string $directory): void
    {
        try {
            $files = LazyCollection::make(File::files($directory));
            \Log::channel('stdout')->info('Found ' . $files->count() . ' files in ' . $directory);

            $movieInfo = $this->parseMovieFromDirectoryName($directory);
            if (!$movieInfo) {
                \Log::channel('stdout')->error('Failed to parse movie info from directory name', [
                    'directory' => $directory,
                ]);
                return;
            }

            $movie = $this->findOrCreateMovie($movieInfo);
            \Log::channel('stdout')->info('Movie found/created', ['movie_id' => $movie->id, 'title' => $movie->title]);

            $files->each(function (\SplFileInfo $file) {
                try {
                    $mimeType = mime_content_type($file->getRealPath());
                } catch (\Exception $e) {
                    \Log::channel('stdout')->error('Failed to get mime type for file: ' . $file->getFilename(), [
                        'path' => $file->getRealPath(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            });
            $videoFiles = $files->filter(function (\SplFileInfo $file) {
                try {
                    $mimeType = mime_content_type($file->getRealPath());
                    return explode('/', $mimeType)[0] === 'video';
                } catch (\Exception $e) {
                    \Log::channel('stdout')->error('Failed to get mime type for file: ' . $file->getFilename(), [
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            });

            \Log::channel('stdout')->info('Found video files', ['count' => $videoFiles->count()]);

            if ($videoFiles->count() > 0) {
                \Log::channel('stdout')->info('Processing video files...');
                $this->processVideoFiles($videoFiles, $movie);
                \Log::channel('stdout')->info('Finished processing video files');
            } else {
                \Log::channel('stdout')->info('No video files found in directory');
            }
        } catch (\Exception $e) {
            \Log::channel('stdout')->error('Failed to scan directory: ' . $directory, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function processVideoFiles(LazyCollection $videoFiles, Movie $movie): void
    {
        try {
            $maxOrder = DB::table('movie_video')
                ->where('movie_id', $movie->id)
                ->max('order') ?? 0;
            $nextOrder = $maxOrder + 1;

            $videoFiles->chunk(50)->each(function ($chunk) use ($movie, &$nextOrder) {
                try {
                    $fileData = [];
                    foreach ($chunk as $file) {
                        $hash = Video::makeHash($file);
                        $fileData[$hash] = [
                            'file' => $file,
                            'hash' => $hash,
                        ];
                    }

                    $existingVideos = Video::select('id', 'hash')
                        ->whereIn('hash', array_keys($fileData))
                        ->get()
                        ->keyBy('hash');

                    // Get existing video IDs for association check
                    $existingVideoIds = $existingVideos->map(fn($video) => $video->id)->toArray();

                    // Single query to check which videos are already associated with this movie
                    $associatedVideoIds = [];
                    if (!empty($existingVideoIds)) {
                        $associatedVideoIds = DB::table('movie_video')
                            ->select('video_id')
                            ->where('movie_id', $movie->id)
                            ->whereIn('video_id', $existingVideoIds)
                            ->get()
                            ->map(fn($row) => $row->video_id)
                            ->toArray();
                    }

                    $videosToCreate = [];
                    $videosToAssociate = [];

                    foreach ($fileData as $hash => $data) {
                        $file = $data['file'];

                        if ($existingVideos->has($hash)) {
                            // Video exists, check if it needs to be associated
                            $video = $existingVideos[$hash];
                            if (!in_array($video->id, $associatedVideoIds)) {
                                $videosToAssociate[] = [
                                    'movie_id' => $movie->id,
                                    'video_id' => $video->id,
                                    'order' => $nextOrder++
                                ];
                            }
                        } else {
                            // Video doesn't exist, prepare for bulk creation
                            $videosToCreate[] = [
                                'hash' => $hash,
                                'path' => $file->getRealPath(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }

                    // Bulk insert new videos
                    if (!empty($videosToCreate)) {
                        Video::insert($videosToCreate);

                        // Get the newly created video IDs
                        $newVideoHashes = array_column($videosToCreate, 'hash');
                        $newVideos = Video::select('id', 'hash')
                            ->whereIn('hash', $newVideoHashes)
                            ->get()
                            ->keyBy('hash');

                        // Prepare associations for new videos
                        foreach ($videosToCreate as $videoData) {
                            $video = $newVideos[$videoData['hash']];
                            $videosToAssociate[] = [
                                'movie_id' => $movie->id,
                                'video_id' => $video->id,
                                'order' => $nextOrder++
                            ];
                        }
                    }

                    if (!empty($videosToAssociate)) {
                        DB::table('movie_video')->insert($videosToAssociate);
                    }
                } catch (\Exception $e) {
                    \Log::channel('stdout')->error('Failed to process video files chunk', [
                        'movie_id' => $movie->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            });
        } catch (\Exception $e) {
            \Log::channel('stdout')->error('Failed to process video files', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function parseMovieFromDirectoryName(string $directory): ?array
    {
        $directoryName = basename($directory);

        // Pattern to match the "Title (Year)" format
        $pattern = '/^(.+?)\s*\((\d{4})\)\s*$/';

        if (preg_match($pattern, $directoryName, $matches)) {
            return [
                'title' => trim($matches[1]),
                'year' => (int) $matches[2]
            ];
        }

        // Alternative patterns for different formats
        $alternativePatterns = [
            // "Title - Year" format
            '/^(.+?)\s*-\s*(\d{4})\s*$/',
            // "Title.Year" format (dot separated)
            '/^(.+?)\.(\d{4})\s*$/',
            // "Title Year" format (space separated, year at the end)
            '/^(.+?)\s+(\d{4})\s*$/',
        ];

        foreach ($alternativePatterns as $altPattern) {
            if (preg_match($altPattern, $directoryName, $matches)) {
                return [
                    'title' => trim($matches[1]),
                    'year' => (int) $matches[2]
                ];
            }
        }

        // If no year is found, check if we have just a title
        if (!empty(trim($directoryName))) {
            return [
                'title' => trim($directoryName),
                'year' => null
            ];
        }

        return null;
    }

    private function findOrCreateMovie(array $movieInfo): Movie
    {
        // Create a cache key for this movie
        $cacheKey = $movieInfo['title'] . '|' . ($movieInfo['year'] ?? 'null');

        if (isset($this->movieCache[$cacheKey])) {
            return $this->movieCache[$cacheKey];
        }

        $query = Movie::where('title', $movieInfo['title']);

        if ($movieInfo['year']) {
            $query->where('year', $movieInfo['year']);
        } else {
            $query->whereNull('year');
        }

        $movie = $query->first();

        if (!$movie) {
            $movie = new Movie([
                'title' => $movieInfo['title'],
                'year' => $movieInfo['year'],
            ]);
            $movie->library()->associate($this->library);
            $movie->saveOrFail();
        }

        // Cache the movie for this job instance
        $this->movieCache[$cacheKey] = $movie;

        return $movie;
    }
}