<?php

namespace App\Jobs\Library\Music;

use App\Events\LibraryScanCompleted;
use App\Jobs\BaseJob;
use App\Models\Library;
use Log;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;
use Throwable;

class ScanMusicLibraryJob extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    public function __construct(private readonly Library $library)
    {
    }

    public function middleware(): array
    {
        // Release after 30 minutes if now complete
        return [new WithoutOverlapping($this->library->id)];
    }

    public function handle(): void
    {
        $this->queueProgress(0);

        $this->library->updateLastScan();
        $path = $this->library->path;

        // Get all subdirectories and include the root directory
        $directories = LazyCollection::make(File::directories($path))
            ->concat([$path]);

        $totalDirectories = count($directories);
        $processedDirectories = 0;
        $chunkSize = config('scanner.music.directory_chunk_size');

        $directories->chunk($chunkSize)->each(function ($chunk) use (&$processedDirectories, $totalDirectories, &$chunkSize) {
            foreach ($chunk as $directory) {
                ScanDirectoryJob::dispatch($directory, $this->library);
                $processedDirectories++;
                $this->queueProgressChunk($totalDirectories, $chunkSize);
            }
        });

        $this->queueProgress(100);
        $this->queueData(['processedDirectories' => $processedDirectories]);
        LibraryScanCompleted::dispatch($this->library);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ScanMusicLibraryJob permanently failed', [
            'library_id' => $this->library->id,
            'error'      => $exception->getMessage(),
        ]);
    }

}