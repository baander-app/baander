<?php

namespace App\Jobs\Library\Music;

use App\Events\LibraryScanCompleted;
use App\Jobs\BaseJob;
use App\Models\Library;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;
use Psr\Log\LoggerInterface;
use Throwable;

class ScanMusicLibraryJob extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

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

        $directories = LazyCollection::make(File::directories($path));

        $this->getLogger()->info('Found ' . $directories->count() . ' directories in ' . $path);

        $totalDirectories = count($directories);
        $processedDirectories = 0;
        $chunkSize = config('scanner.music.directory_chunk_size');

        $directories->chunk($chunkSize)->each(function ($chunk) use (&$processedDirectories, $totalDirectories, &$chunkSize) {
            $this->getLogger()->info('Processing ' . $processedDirectories . '/' . $totalDirectories . ' directories');
            foreach ($chunk as $directory) {
                ScanDirectoryJob::dispatch($directory, $this->library);
                $processedDirectories++;
                $this->queueProgressChunk($totalDirectories, $chunkSize);
            }
        });

        $this->getLogger()->info('Scan complete');

        $this->queueProgress(100);
        $this->queueData(['processedDirectories' => $processedDirectories]);
        LibraryScanCompleted::dispatch($this->library);
    }

    public function failed(Throwable $exception): void
    {
        $this->getLogger()->error('ScanMusicLibraryJob permanently failed', [
            'library_id' => $this->library->id,
            'error'      => $exception->getMessage(),
        ]);
    }

}