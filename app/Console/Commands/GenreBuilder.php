<?php

namespace App\Console\Commands;

use App\Modules\Metadata\GenreHierarchyService;
use Illuminate\Console\Command;

class GenreBuilder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:genre-builder {--test-single=} {--test-discogs=} {--test-discogs-detailed=} {--test-discogs-search-only=} {--test-lookup=} {--test-lookup-detailed=} {--debug-search-lookup=} {--test-auth} {--simple} {--limit=5}';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build genre hierarchy from music genres seed file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $builder = app(GenreHierarchyService::class);

        if ($genre = $this->option('debug-search-lookup')) {
            $this->info("Debugging search vs lookup for genre: {$genre}");
            $result = $builder->debugSearchVsLookup($genre);
            dump($result);
            return;
        }

        if ($testGenre = $this->option('test-discogs-search-only')) {
            $this->info("Testing Discogs search-only for genre: {$testGenre}");
            $result = $builder->testDiscogsSearchOnly($testGenre);
            dump($result);
            return;
        }


        if ($genre = $this->option('debug-search-lookup')) {
            $this->info("Debugging search vs lookup for genre: {$genre}");
            $result = $builder->debugSearchVsLookup($genre);
            dump($result);
            return;
        }


        // Test lookup with a specific release ID
        if ($releaseId = $this->option('test-lookup')) {
            $this->info("Testing Discogs lookup for release ID: {$releaseId}");
            $result = $builder->testDiscogsLookup($releaseId);
            dump($result);
            return;
        }

        // Test Discogs authentication
        if ($this->option('test-auth')) {
            $this->info("Testing Discogs authentication");
            $result = $builder->testDiscogsAuth();
            dump($result);
            return;
        }


        // Test simple version without Discogs detailed lookup
        if ($this->option('simple')) {
            $this->info("Using simple version (string matching only)");

            $content = \File::get(storage_path('metadata/music_genres_seed.csv'));
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $genres = array_filter(array_slice($lines, 1), fn($line) => !empty(trim($line)));
            $genres = array_map('trim', $genres);

            $limit = (int) $this->option('limit');
            $genres = array_slice($genres, 0, $limit);

            $this->info("Processing {$limit} genres: " . implode(', ', $genres));
            $result = $builder->buildGenreHierarchySimple($genres);
            dump($result);
            return;
        }


        if ($testGenre = $this->option('test-discogs-detailed')) {
            $this->info("Testing Discogs detailed for genre: {$testGenre}");
            $result = $builder->testDiscogsGenreDetailed($testGenre);
            dump($result);
            return;
        }


        // Test Discogs specifically
        if ($testGenre = $this->option('test-discogs')) {
            $this->info("Testing Discogs for genre: {$testGenre}");
            $result = $builder->testDiscogsGenre($testGenre);
            dump($result);
            return;
        }

        // Test single genre if specified
        if ($testGenre = $this->option('test-single')) {
            $this->info("Testing single genre: {$testGenre}");
            $result = $builder->testSingleGenre($testGenre);
            dump($result);
            return;
        }

        // Fix CSV parsing - handle both \r\n and \n line endings
        $content = \File::get(storage_path('metadata/music_genres_seed.csv'));
        $lines = preg_split('/\r\n|\r|\n/', $content);

        // Remove header line and filter empty lines, trim each line
        $genres = array_filter(array_slice($lines, 1), function($line) {
            return !empty(trim($line));
        });

        // Trim all genre names to remove any remaining whitespace
        $genres = array_map('trim', $genres);

        // Take limited number of genres for testing
        $limit = (int) $this->option('limit');
        $genres = array_slice($genres, 0, $limit);

        if(empty($genres)) {
            $this->error('No genres found in file');
            return;
        }

        $this->info("Processing {$limit} genres: " . implode(', ', $genres));

        $res = $builder->buildGenreHierarchyBatch($genres);

        dump($res);
    }
}