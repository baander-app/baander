<?php

namespace App\Console\Commands\Music;

use App\Modules\Metadata\Providers\Local\AlbumCoverService;
use Illuminate\Console\Command;

class QueueAlbumCoversCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'music:queue-album-covers 
                            {--force : Force re-processing of albums that already have covers}
                            {--limit= : Limit the number of albums to process}
                            {--library= : Only process albums from specific library ID}
                            {--stats : Show cover statistics only}
                            {--clear-queued : Clear queued status for all albums}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue jobs to generate covers for albums without covers';

    public function __construct(
        private readonly AlbumCoverService $albumCoverService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle stats option
        if ($this->option('stats')) {
            return $this->showStatistics();
        }

        // Handle clear queued option
        if ($this->option('clear-queued')) {
            return $this->clearQueuedStatus();
        }

        return $this->queueAlbumCovers();
    }

    /**
     * Queue album cover jobs
     */
    private function queueAlbumCovers(): int
    {
        $options = array_filter([
            'force' => $this->option('force'),
            'limit' => $this->option('limit'),
            'library_id' => $this->option('library'),
        ]);

        $this->info('Finding albums without covers...');

        $result = $this->albumCoverService->queueMissingCovers($options);

        if ($result['found'] === 0) {
            $this->info('No albums found that need cover processing.');
            return self::SUCCESS;
        }

        $this->info("Found {$result['found']} albums to process.");

        // Show progress if processing a reasonable number of albums
        if ($result['found'] <= 1000) {
            $progressBar = $this->output->createProgressBar($result['found']);
            $progressBar->start();
            $progressBar->setProgress($result['queued'] + $result['skipped']);
            $progressBar->finish();
            $this->newLine(2);
        }

        $this->info("Queued {$result['queued']} cover extraction jobs.");

        if ($result['skipped'] > 0) {
            $this->warn("Skipped {$result['skipped']} albums (no songs or already queued).");
        }

        return self::SUCCESS;
    }

    /**
     * Show cover statistics
     */
    private function showStatistics(): int
    {
        $libraryId = $this->option('library');
        $stats = $this->albumCoverService->getCoverStatistics($libraryId);

        $this->info('Album Cover Statistics');
        $this->info('=====================');

        if ($libraryId) {
            $this->info("Library ID: {$libraryId}");
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Albums', number_format($stats['total_albums'])],
                ['Albums with Covers', number_format($stats['albums_with_covers'])],
                ['Albums without Covers', number_format($stats['albums_without_covers'])],
                ['Coverage Percentage', $stats['coverage_percentage'] . '%'],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Clear queued status for albums
     */
    private function clearQueuedStatus(): int
    {
        $this->warn('This will clear the queued status for all albums.');

        if (!$this->confirm('Are you sure you want to continue?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $cleared = $this->albumCoverService->clearQueuedStatus();

        $this->info("Cleared queued status for {$cleared} albums.");

        return self::SUCCESS;
    }
}