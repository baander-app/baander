<?php

namespace App\Console\Commands;

use App\Modules\Metadata\MetadataJobDispatcher;
use Illuminate\Console\Command;

class SyncMetadataCommand extends Command
{
    protected $signature = 'metadata:sync 
                           {--album=* : Specific album IDs to sync}
                           {--song=* : Specific song IDs to sync}
                           {--artist=* : Specific artist IDs to sync}
                           {--library= : Library ID to sync}
                           {--include-songs : Include songs when syncing albums}
                           {--include-artists : Include artists when syncing albums}
                           {--force : Force update existing metadata}
                           {--batch-size=10 : Number of items to process in each batch}
                           {--queue=default : Queue name for processing jobs}';

    protected $description = 'Sync metadata from external sources (MusicBrainz, Discogs) for albums, songs, and artists';

    public function __construct(
        private readonly MetadataJobDispatcher $metadataSyncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $albumIds = array_filter($this->option('album'));
        $songIds = array_filter($this->option('song'));
        $artistIds = array_filter($this->option('artist'));
        $libraryId = $this->option('library') ? (int) $this->option('library') : null;
        $includeSongs = $this->option('include-songs');
        $includeArtists = $this->option('include-artists');
        $forceUpdate = $this->option('force');
        $batchSize = (int) $this->option('batch-size');
        $queueName = $this->option('queue');

        // Validate input
        if (!$libraryId && empty($albumIds) && empty($songIds) && empty($artistIds)) {
            $this->error('Please specify what to sync: --album, --song, --artist, or --library');
            $this->line('Use --help for more information');
            return self::FAILURE;
        }

        // Validate IDs if provided
        if (!empty($albumIds) || !empty($songIds) || !empty($artistIds)) {
            $validation = $this->metadataSyncService->validateIds($albumIds, $songIds, $artistIds);

            if (!empty($validation['albums']['invalid'])) {
                $this->warn('Invalid album IDs: ' . implode(', ', $validation['albums']['invalid']));
            }
            if (!empty($validation['songs']['invalid'])) {
                $this->warn('Invalid song IDs: ' . implode(', ', $validation['songs']['invalid']));
            }
            if (!empty($validation['artists']['invalid'])) {
                $this->warn('Invalid artist IDs: ' . implode(', ', $validation['artists']['invalid']));
            }
        }

        // Show library stats if syncing entire library
        if ($libraryId) {
            $stats = $this->metadataSyncService->getLibraryStats($libraryId);
            $this->info("Library {$libraryId} contains:");
            $this->line("  - Albums: {$stats['albums']}");
            $this->line("  - Songs: {$stats['songs']}");
            $this->line("  - Artists: {$stats['artists']}");
            if ($stats['orphaned_songs'] > 0) {
                $this->line("  - Orphaned Songs: {$stats['orphaned_songs']}");
            }
        }

        // Create progress callback
        $progressCallback = $this->createProgressCallback();

        // Sync metadata
        $totalJobs = $this->metadataSyncService->syncMixed(
            $albumIds,
            $songIds,
            $artistIds,
            $libraryId,
            $forceUpdate,
            $batchSize,
            $queueName,
            $includeSongs,
            $includeArtists,
            $progressCallback
        );

        if ($totalJobs > 0) {
            $this->info("Successfully queued {$totalJobs} metadata sync jobs.");
        } else {
            $this->info('No items found to sync.');
        }

        return self::SUCCESS;
    }

    private function createProgressCallback(): callable
    {
        $progressBars = [];

        return function (int $processed, int $total, string $type) use (&$progressBars) {
            // Create progress bar for each type if it doesn't exist
            if (!isset($progressBars[$type])) {
                $progressBars[$type] = $this->output->createProgressBar($total);
                $progressBars[$type]->setFormat(ucfirst($type) . ': %current%/%max% [%bar%] %percent:3s%%');
                $progressBars[$type]->start();
            }

            // Advance progress bar
            $progressBars[$type]->advance();

            // Finish progress bar when complete
            if ($processed === $total) {
                $progressBars[$type]->finish();
                $this->newLine();
            }
        };
    }
}