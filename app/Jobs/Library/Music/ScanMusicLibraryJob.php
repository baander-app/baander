<?php

namespace App\Jobs\Library\Music;

use App\Events\LibraryScanCompleted;
use App\Jobs\BaseJob;
use App\Models\Library;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;

class ScanMusicLibraryJob extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    public string $logChannel = 'music';

    public function __construct(public Library $library)
    {
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->library->id)->dontRelease()];
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
}