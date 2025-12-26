<?php

namespace App\Modules\Development\Console\Commands;

use App\Modules\Metadata\GenreHierarchyService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class BuildGenreHierarchyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genres:build
                            {genres?* : List of genre names to process}
                            {--batch : Use batch processing mode (slower but more robust)}
                            {--batch-size=5 : Number of genres to process per batch}
                            {--file= : Load genres from a file (one per line)}
                            {--from-db : Load all genres from the database}
                            {--persist : Persist hierarchy to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build genre hierarchies from LastFM, Discogs, and MusicBrainz';

    public function __construct(
        private readonly GenreHierarchyService $genreHierarchy,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $genres = $this->getGenres();

        if (empty($genres)) {
            $this->error('No genres to process. Provide genres as arguments or use --from-db or --file option.');
            return SymfonyCommand::FAILURE;
        }

        $genreCount = count($genres);

        // Warn about large datasets
        if ($genreCount > 500) {
            $this->warn("Processing {$genreCount} genres may take a long time and use significant memory.");
            $this->warn('Consider processing in smaller batches or use --batch mode.');

            if (!$this->confirm('Continue?', default: false)) {
                return SymfonyCommand::SUCCESS;
            }
        }

        $this->info("Building hierarchies for {$genreCount} genres...");

        $useBatch = $this->option('batch');
        $batchSize = (int) $this->option('batch-size');
        $persist = $this->option('persist');

        try {
            if ($useBatch) {
                $this->buildBatch($genres, $batchSize, $persist);
            } else {
                $this->buildSimple($genres, $persist);
            }

            $this->info('✓ Genre hierarchy build completed successfully!');
            return SymfonyCommand::SUCCESS;

        } catch (\Exception $e) {
            $this->error("✗ Failed to build genre hierarchy: {$e->getMessage()}");
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return SymfonyCommand::FAILURE;
        }
    }

    /**
     * Get the list of genres to process
     */
    private function getGenres(): array
    {
        // From file
        if ($file = $this->option('file')) {
            if (!file_exists($file)) {
                $this->error("File not found: $file");
                return [];
            }

            $genres = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->info("Loaded " . count($genres) . " genres from file: $file");
            return $genres;
        }

        // From database
        if ($this->option('from-db')) {
            $genres = \App\Models\Genre::pluck('name')->toArray();
            $this->info("Loaded " . count($genres) . " genres from database");
            return $genres;
        }

        // From arguments
        return (array) $this->argument('genres');
    }

    /**
     * Build hierarchies using batch processing
     */
    private function buildBatch(array $genres, int $batchSize, bool $persist): void
    {
        $this->info("Using batch processing mode (batch size: $batchSize)");

        // Build hierarchy for all genres at once
        $hierarchy = $this->genreHierarchy->buildGenreHierarchyBatch($genres, $batchSize);

        $this->displayResults($hierarchy);

        if ($persist) {
            $this->persistHierarchy($hierarchy);
        }
    }

    /**
     * Build hierarchies using simple processing
     */
    private function buildSimple(array $genres, bool $persist): void
    {
        $this->info('Using simple processing mode');

        // Build hierarchy for all genres at once
        $hierarchy = $this->genreHierarchy->buildGenreHierarchySimple($genres);

        $this->displayResults($hierarchy);

        if ($persist) {
            $this->persistHierarchy($hierarchy);
        }
    }

    /**
     * Display hierarchy results
     */
    private function displayResults(array $hierarchy): void
    {
        $this->newLine();

        $rootGenres = $hierarchy['root_genres'] ?? [];
        $subgenres = $hierarchy['subgenres'] ?? [];
        $relationships = $hierarchy['relationships'] ?? [];
        $genreDetails = $hierarchy['genre_details'] ?? [];

        $this->info("✓ Found " . count($rootGenres) . " root genres");
        $this->info("✓ Found " . count($subgenres) . " subgenres");
        $this->info("✓ Created " . count($relationships) . " relationships");

        $this->newLine();

        // Show some relationships
        if (count($relationships) > 0) {
            $this->info('Sample relationships:');
            foreach (array_slice($relationships, 0, 10) as $rel) {
                $similarity = round($rel['similarity'] * 100, 1);
                $source = $rel['source'];
                $this->line("  {$rel['child']} → {$rel['parent']} ({$similarity}% match, {$source})");
            }

            if (count($relationships) > 10) {
                $this->line("  ... and " . (count($relationships) - 10) . " more");
            }
        }
    }

    /**
     * Persist hierarchy to database
     */
    private function persistHierarchy(array $hierarchy): void
    {
        $this->newLine();
        $this->info('Persisting hierarchy to database...');

        $relationships = $hierarchy['relationships'] ?? [];
        $stats = ['created' => 0, 'updated' => 0, 'linked' => 0];

        \Illuminate\Support\Facades\DB::transaction(function () use ($relationships, &$stats) {
            foreach ($relationships as $relationship) {
                $childName = $relationship['child'];
                $parentName = $relationship['parent'];

                // Ensure child genre exists
                $child = \App\Models\Genre::firstOrCreate([
                    'name' => $childName,
                ], [
                    'slug' => \Illuminate\Support\Str::slug($childName),
                ]);

                if ($child->wasRecentlyCreated) {
                    $stats['created']++;
                }

                // Ensure parent genre exists
                $parent = \App\Models\Genre::firstOrCreate([
                    'name' => $parentName,
                ], [
                    'slug' => \Illuminate\Support\Str::slug($parentName),
                ]);

                if ($parent->wasRecentlyCreated) {
                    $stats['created']++;
                }

                // Link child to parent
                if ($child->parent_id !== $parent->id) {
                    $child->update(['parent_id' => $parent->id]);
                    $stats['linked']++;
                } else {
                    $stats['updated']++;
                }
            }
        });

        $this->info("✓ Created {$stats['created']} genres");
        $this->info("✓ Updated {$stats['updated']} existing relationships");
        $this->info("✓ Linked {$stats['linked']} new parent-child relationships");
    }
}
