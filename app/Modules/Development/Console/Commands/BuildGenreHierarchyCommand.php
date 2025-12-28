<?php

namespace App\Modules\Development\Console\Commands;

use App\Format\TextSimilarity;
use App\Models\Genre;
use App\Modules\Metadata\GenreHierarchyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        private readonly TextSimilarity $textSimilarity,
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
            $genres = Genre::pluck('name')->toArray();
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
     * Find an existing genre by name (case-insensitive, using TextSimilarity)
     *
     * @param string $genreName The genre name to find
     * @param \Illuminate\Support\Collection $allGenres All genres in database
     * @param array $genreCache Cache of normalized names to genre models
     * @return Genre|null Existing genre or null if not found
     */
    private function findExistingGenre(string $genreName, \Illuminate\Support\Collection $allGenres, array &$genreCache): ?Genre
    {
        $normalizedName = $this->textSimilarity->normalizeInternationalText($genreName);

        // Check cache first
        if (isset($genreCache[$normalizedName])) {
            return $genreCache[$normalizedName];
        }

        // First try exact match (fastest)
        $exactMatch = $allGenres->firstWhere('name', $genreName);
        if ($exactMatch) {
            $genreCache[$normalizedName] = $exactMatch;
            return $exactMatch;
        }

        // Try case-insensitive match using normalized comparison
        foreach ($allGenres as $genre) {
            $existingNormalized = $this->textSimilarity->normalizeInternationalText($genre->name);
            if ($existingNormalized === $normalizedName) {
                $genreCache[$normalizedName] = $genre;
                $genreCache[$existingNormalized] = $genre; // Cache both normalizations
                return $genre;
            }
        }

        // Use TextSimilarity to find near-exact matches (handle slight variations)
        $matches = $this->textSimilarity->findBestMatches(
            $genreName,
            $allGenres->pluck('name')->toArray(),
            threshold: 95.0, // Very high threshold - only match if essentially the same
            limit: 1
        );

        if (!empty($matches)) {
            $match = $matches[0];
            $matchedGenre = $allGenres->firstWhere('name', $match['text']);

            if ($matchedGenre && $match['similarity'] >= 99.0) {
                // Only use if it's an almost perfect match (handles diacritics, spacing, etc.)
                $genreCache[$normalizedName] = $matchedGenre;
                return $matchedGenre;
            }
        }

        // Cache that we didn't find anything
        $genreCache[$normalizedName] = null;
        return null;
    }

    /**
     * Persist hierarchy to database
     */
    private function persistHierarchy(array $hierarchy): void
    {
        $this->newLine();
        $this->info('Persisting hierarchy to database...');

        $relationships = $hierarchy['relationships'] ?? [];
        $stats = ['created' => 0, 'matched' => 0, 'linked' => 0];
        $allGenres = Genre::all(['id', 'name']);
        $genreCache = []; // Cache of genre names to models for quick lookup

        DB::transaction(function () use ($relationships, &$stats, $allGenres, &$genreCache) {
            foreach ($relationships as $relationship) {
                $childName = $relationship['child'];
                $parentName = $relationship['parent'];

                // Try to find existing child genre (case-insensitive)
                $child = $this->findExistingGenre($childName, $allGenres, $genreCache);

                if (!$child) {
                    // Create new child genre if not found
                    $child = Genre::create([
                        'name' => $childName,
                        'slug' => \Illuminate\Support\Str::slug($childName),
                    ]);
                    $stats['created']++;
                } else {
                    $stats['matched']++;
                }

                // Try to find existing parent genre (case-insensitive)
                $parent = $this->findExistingGenre($parentName, $allGenres, $genreCache);

                if (!$parent) {
                    // Create new parent genre if not found
                    $parent = Genre::create([
                        'name' => $parentName,
                        'slug' => \Illuminate\Support\Str::slug($parentName),
                    ]);
                    $stats['created']++;
                } else {
                    $stats['matched']++;
                }

                // Link child to parent
                if ($child->parent_id !== $parent->id) {
                    $child->update(['parent_id' => $parent->id]);
                    $stats['linked']++;
                }
            }
        });

        $this->info("✓ Created {$stats['created']} new genres");
        $this->info("✓ Matched {$stats['matched']} existing genres (case-insensitive)");
        $this->info("✓ Linked {$stats['linked']} parent-child relationships");
    }
}
