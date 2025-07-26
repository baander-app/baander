<?php

namespace App\Modules\Recommendation\Calculators;

use App\Modules\Metadata\GenreHierarchyService;
use App\Modules\Recommendation\Contracts\CalculatorInterface;
use Illuminate\Support\Facades\{Cache, DB, Log};

class MusicGenreSimilarityCalculator implements CalculatorInterface
{
    public function __construct(
        private readonly GenreHierarchyService $genreHierarchyService,
    ) {
    }

    /**
     * Calculate recommendations based on music genre similarity
     *
     * @param mixed $sourceData Source model(s) to calculate recommendations for
     * @param array $configuration Configuration parameters for calculation
     * @return array Array of recommendations [sourceId => [targetId => score]]
     * @throws \InvalidArgumentException
     */
    public function calculate($sourceData, array $configuration): array
    {
        // Validate required configuration parameters
        $this->validateConfiguration($configuration);

        // Extract configuration parameters
        $dataTable = $configuration['data_table'];
        $dataField = $configuration['data_field'];
        $dataFieldType = $configuration['data_field_type'];
        $groupField = $configuration['group_field'];
        $maxRecommendations = $configuration['count'] ?? config('recommendation.defaults.count', 10);

        // Query the database to get relationship data
        $query = DB::table($dataTable)
            ->select(
                DB::raw("{$groupField} as group_field"),
                DB::raw("{$dataField} as data_field"),
            );

        // Apply data filters if specified
        if (isset($configuration['data_table_filter']) && is_array($configuration['data_table_filter'])) {
            foreach ($configuration['data_table_filter'] as $field => $filterConfig) {
                $this->applyFilter($query, $field, $filterConfig);
            }
        }

        $relationData = $query->get();

        Log::info("Genre similarity calculator data loaded", [
            'total_relations' => $relationData->count(),
            'sample_relations' => $relationData->take(5)->toArray()
        ]);

        if ($relationData->isEmpty()) {
            Log::warning("No relationship data found for genre similarity calculation");
            return [];
        }

        // Group the data by the group field (genres) and data field (songs)
        $songGenres = []; // song_id => [genre1, genre2, ...]
        $genreSongs = []; // genre_id => [song1, song2, ...]
        $allGenreIds = [];

        foreach ($relationData as $row) {
            // Map songs to their genres
            if (!isset($songGenres[$row->data_field])) {
                $songGenres[$row->data_field] = [];
            }
            $songGenres[$row->data_field][] = $row->group_field;
            $allGenreIds[] = $row->group_field;

            // Map genres to their songs
            if (!isset($genreSongs[$row->group_field])) {
                $genreSongs[$row->group_field] = [];
            }
            $genreSongs[$row->group_field][] = $row->data_field;
        }

        $allGenreIds = array_unique($allGenreIds);

        Log::info("Grouped genre data", [
            'total_songs' => count($songGenres),
            'total_genres' => count($allGenreIds),
            'sample_song_genres' => array_slice($songGenres, 0, 3, true),
            'sample_genre_songs' => array_slice($genreSongs, 0, 3, true)
        ]);

        // Get genre names for the found IDs
        $genreNames = $this->getGenreNamesById($allGenreIds);

        if (empty($genreNames)) {
            Log::warning("No genre names found for IDs", ['genre_ids' => $allGenreIds]);
            return [];
        }

        Log::info("Found genre names", [
            'genre_count' => count($genreNames),
            'genres' => array_values($genreNames)
        ]);

        // Build genre hierarchy for similarity calculations
        $hierarchy = $this->genreHierarchyService->buildGenreHierarchySimple(array_values($genreNames));

        Log::info("Built genre hierarchy", [
            'genre_details_count' => count($hierarchy['genre_details'] ?? []),
            'similarity_matrix_size' => count($hierarchy['similarity_matrix'] ?? []),
            'sample_genre_details' => array_slice($hierarchy['genre_details'] ?? [], 0, 2, true)
        ]);

        // Calculate recommendations based on genre similarity
        $recommendations = [];
        $totalRecommendations = 0;

        foreach ($songGenres as $songId => $songGenreIds) {
            $songRecommendations = [];

            // Convert genre IDs to names
            $songGenreNames = [];
            foreach ($songGenreIds as $genreId) {
                if (isset($genreNames[$genreId])) {
                    $songGenreNames[] = $genreNames[$genreId];
                }
            }

            if (empty($songGenreNames)) {
                continue;
            }

            Log::debug("Processing song", [
                'song_id' => $songId,
                'genre_names' => $songGenreNames
            ]);

            // Find similar genres for each of the song's genres
            $similarGenres = [];
            foreach ($songGenreNames as $genreName) {
                // Get related genres from hierarchy
                $genreDetails = $hierarchy['genre_details'][$genreName] ?? [];
                $relationships = $genreDetails['relationships'] ?? [];

                foreach ($relationships as $relationship) {
                    $similarGenreName = $relationship['name'];
                    $similarity = $relationship['match'];

                    // Find the genre ID for this similar genre
                    $similarGenreId = array_search($similarGenreName, $genreNames);
                    if ($similarGenreId !== false) {
                        if (!isset($similarGenres[$similarGenreId])) {
                            $similarGenres[$similarGenreId] = 0;
                        }
                        // Use weighted average instead of max to account for multiple genre connections
                        $currentWeight = $similarGenres[$similarGenreId];
                        $newWeight = ($currentWeight + $similarity) / (($currentWeight > 0 ? 1 : 0) + 1);
                        $similarGenres[$similarGenreId] = $newWeight;
                    }
                }
            }

            Log::debug("Found similar genres for song", [
                'song_id' => $songId,
                'similar_genres_count' => count($similarGenres)
            ]);

            // Find songs in similar genres with improved scoring
            foreach ($similarGenres as $similarGenreId => $similarity) {
                if (isset($genreSongs[$similarGenreId])) {
                    $songsInGenre = $genreSongs[$similarGenreId];
                    $genrePopularity = count($songsInGenre); // Number of songs in this genre

                    foreach ($songsInGenre as $recommendedSongId) {
                        // Skip self-recommendations
                        if ($recommendedSongId === $songId) {
                            continue;
                        }

                        // Check how many genres this recommended song shares with the source song
                        $recommendedSongGenres = $songGenres[$recommendedSongId] ?? [];
                        $sharedGenres = array_intersect($songGenreIds, $recommendedSongGenres);
                        $sharedGenreBonus = count($sharedGenres) * 0.1; // 10% bonus per shared genre

                        // Calculate genre rarity bonus (rarer genres get higher scores)
                        $rarityBonus = $genrePopularity > 0 ? (1 / log($genrePopularity + 1)) * 0.2 : 0;

                        // Calculate final score with multiple factors
                        $baseScore = $similarity * 1000; // Scale to 0-1000 range for better precision
                        $adjustedScore = $baseScore + ($baseScore * $sharedGenreBonus) + ($baseScore * $rarityBonus);

                        // Add or update recommendation score
                        if (!isset($songRecommendations[$recommendedSongId])) {
                            $songRecommendations[$recommendedSongId] = 0;
                        }

                        // Use the maximum score rather than accumulating to avoid bias toward multi-genre songs
                        $songRecommendations[$recommendedSongId] = max(
                            $songRecommendations[$recommendedSongId],
                            $adjustedScore
                        );
                    }
                }
            }

            // Sort recommendations by score and limit to max recommendations
            if (!empty($songRecommendations)) {
                arsort($songRecommendations);
                $topRecommendations = array_slice($songRecommendations, 0, $maxRecommendations, true);

                // Convert scores to integers and ensure minimum quality threshold
                $integerRecommendations = [];
                foreach ($topRecommendations as $targetId => $score) {
                    $integerScore = (int) round($score);
                    if ($integerScore > 50) { // Minimum quality threshold (scaled to new range)
                        $integerRecommendations[$targetId] = $integerScore;
                    }
                }

                if (!empty($integerRecommendations)) {
                    $recommendations[$songId] = $integerRecommendations;
                    $totalRecommendations += count($integerRecommendations);

                    Log::debug("Generated recommendations for song", [
                        'song_id' => $songId,
                        'source_genres' => $songGenreNames,
                        'recommendations_count' => count($integerRecommendations),
                        'top_recommendation_score' => array_values($integerRecommendations)[0] ?? 0,
                        'score_range' => [
                            'min' => min($integerRecommendations),
                            'max' => max($integerRecommendations)
                        ]
                    ]);
                }
            }
        }

        // Validate recommendation quality
        $recommendations = $this->validateRecommendationQuality($recommendations, $songGenres);

        // Analyze recommendation quality for debugging
        $qualityAnalysis = $this->analyzeRecommendationQuality($recommendations, $songGenres, $genreNames);
        Log::info("Recommendation quality analysis", $qualityAnalysis);

        Log::info("Genre similarity calculation complete", [
            'songs_processed' => count($songGenres),
            'songs_with_recommendations' => count($recommendations),
            'total_recommendations' => $totalRecommendations
        ]);

        return $recommendations;
    }

    /**
     * Validate recommendation quality
     */
    private function validateRecommendationQuality(array $recommendations, array $songGenres): array
    {
        $validatedRecommendations = [];

        foreach ($recommendations as $sourceId => $targets) {
            $sourceGenres = $songGenres[$sourceId] ?? [];
            $validTargets = [];

            foreach ($targets as $targetId => $score) {
                $targetGenres = $songGenres[$targetId] ?? [];

                // Skip if target has no genres
                if (empty($targetGenres)) {
                    continue;
                }

                // Check if there's at least some genre relationship
                $hasGenreConnection = false;
                foreach ($sourceGenres as $sourceGenre) {
                    foreach ($targetGenres as $targetGenre) {
                        if ($this->calculateGenreSimilarity($sourceGenre, $targetGenre) > 0.1) {
                            $hasGenreConnection = true;
                            break 2;
                        }
                    }
                }

                // Ensure score is integer and meets minimum threshold
                $integerScore = is_int($score) ? $score : (int) round($score);
                if ($hasGenreConnection && $integerScore > 50) { // Adjusted threshold for new scale
                    $validTargets[$targetId] = $integerScore;
                }
            }

            if (!empty($validTargets)) {
                $validatedRecommendations[$sourceId] = $validTargets;
            }
        }

        return $validatedRecommendations;
    }

    /**
     * Analyze recommendation quality for debugging
     */
    public function analyzeRecommendationQuality(array $recommendations, array $songGenres, array $genreNames): array
    {
        $analysis = [
            'total_source_songs' => count($recommendations),
            'avg_recommendations_per_song' => 0,
            'score_distribution' => [],
            'genre_coverage' => [],
            'sample_recommendations' => []
        ];

        $totalRecommendations = 0;
        $allScores = [];

        foreach ($recommendations as $sourceId => $targets) {
            $totalRecommendations += count($targets);
            $sourceGenres = $songGenres[$sourceId] ?? [];
            $sourceGenreNames = array_map(fn($id) => $genreNames[$id] ?? "Unknown($id)", $sourceGenres);

            foreach ($targets as $targetId => $score) {
                $allScores[] = $score;
            }

            // Add sample for first few recommendations
            if (count($analysis['sample_recommendations']) < 3) {
                $targetId = array_key_first($targets);
                $targetGenres = $songGenres[$targetId] ?? [];
                $targetGenreNames = array_map(fn($id) => $genreNames[$id] ?? "Unknown($id)", $targetGenres);

                $analysis['sample_recommendations'][] = [
                    'source_id' => $sourceId,
                    'source_genres' => $sourceGenreNames,
                    'target_id' => $targetId,
                    'target_genres' => $targetGenreNames,
                    'score' => $targets[$targetId],
                    'genre_overlap' => array_intersect($sourceGenres, $targetGenres)
                ];
            }
        }

        if (count($recommendations) > 0) {
            $analysis['avg_recommendations_per_song'] = $totalRecommendations / count($recommendations);
        }

        if (!empty($allScores)) {
            $analysis['score_distribution'] = [
                'min' => min($allScores),
                'max' => max($allScores),
                'avg' => array_sum($allScores) / count($allScores),
                'count' => count($allScores)
            ];
        }

        return $analysis;
    }

    /**
     * Get genre names by their IDs
     */
    private function getGenreNamesById(array $genreIds): array
    {
        if (empty($genreIds)) {
            return [];
        }

        return DB::table('genres')
            ->whereIn('id', $genreIds)
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Validate that required configuration parameters are present
     *
     * @param array $configuration
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateConfiguration(array $configuration): void
    {
        $requiredParams = ['data_table', 'data_field', 'data_field_type', 'group_field'];

        foreach ($requiredParams as $param) {
            if (!isset($configuration[$param])) {
                throw new \InvalidArgumentException("Missing required configuration parameter: {$param}");
            }
        }
    }

    /**
     * Calculate similarity between two genres
     */
    public function calculateGenreSimilarity(string $genre1, string $genre2): float
    {
        if ($genre1 === $genre2) {
            return 1.0;
        }

        $cacheKey = "genre_similarity:" . md5($genre1 . $genre2);

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($genre1, $genre2) {
            return $this->computeGenreSimilarity($genre1, $genre2);
        });
    }

    /**
     * Get related genres for a given genre with similarity scores
     */
    public function getRelatedGenres(string $genre, int $limit = 10): array
    {
        $cacheKey = "related_genres:" . md5($genre) . ":{$limit}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($genre, $limit) {
            return $this->computeRelatedGenres($genre, $limit);
        });
    }

    /**
     * Calculate genre diversity score for a collection of genres
     */
    public function calculateGenreDiversity(array $genres): float
    {
        if (count($genres) <= 1) {
            return 0.0;
        }

        $similarities = [];
        $genreCount = count($genres);

        // Calculate pairwise similarities
        for ($i = 0; $i < $genreCount; $i++) {
            for ($j = $i + 1; $j < $genreCount; $j++) {
                $similarities[] = $this->calculateGenreSimilarity($genres[$i], $genres[$j]);
            }
        }

        // Diversity is inverse of average similarity
        $avgSimilarity = array_sum($similarities) / count($similarities);
        return 1.0 - $avgSimilarity;
    }

    /**
     * Find genre bridges between two sets of genres
     */
    public function findGenreBridges(array $sourceGenres, array $targetGenres, int $limit = 5): array
    {
        $bridges = [];

        foreach ($sourceGenres as $sourceGenre) {
            foreach ($targetGenres as $targetGenre) {
                $similarity = $this->calculateGenreSimilarity($sourceGenre, $targetGenre);

                if ($similarity > 0.3) { // Threshold for meaningful connection
                    $bridges[] = [
                        'source'        => $sourceGenre,
                        'target'        => $targetGenre,
                        'similarity'    => $similarity,
                        'bridge_genres' => $this->findIntermediateGenres($sourceGenre, $targetGenre),
                    ];
                }
            }
        }

        // Sort by similarity and return top bridges
        usort($bridges, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($bridges, 0, $limit);
    }

    /**
     * Calculate genre expansion score (how much a genre can expand user's taste)
     */
    public function calculateExpansionScore(string $newGenre, array $userGenres): float
    {
        if (in_array($newGenre, $userGenres)) {
            return 0.0; // No expansion if user already has this genre
        }

        $maxSimilarity = 0.0;
        foreach ($userGenres as $userGenre) {
            $similarity = $this->calculateGenreSimilarity($newGenre, $userGenre);
            $maxSimilarity = max($maxSimilarity, $similarity);
        }

        // Expansion score is high when genre is related but not too similar
        // Sweet spot around 0.4-0.7 similarity
        if ($maxSimilarity >= 0.4 && $maxSimilarity <= 0.7) {
            return 1.0 - abs(0.55 - $maxSimilarity) * 2; // Peak at 0.55 similarity
        }

        return max(0.0, 0.3 - abs($maxSimilarity - 0.3)); // Diminishing returns outside sweet spot
    }

    /**
     * Get genre recommendation weights for content-based filtering
     */
    public function getRecommendationWeights(array $userGenres, array $candidateGenres): array
    {
        $weights = [];

        foreach ($candidateGenres as $candidateGenre) {
            $maxWeight = 0.0;
            $totalWeight = 0.0;
            $weightCount = 0;

            foreach ($userGenres as $userGenre) {
                $similarity = $this->calculateGenreSimilarity($candidateGenre, $userGenre);
                $maxWeight = max($maxWeight, $similarity);
                $totalWeight += $similarity;
                $weightCount++;
            }

            // Use both max similarity and average, weighted towards max
            $avgWeight = $weightCount > 0 ? $totalWeight / $weightCount : 0.0;
            $finalWeight = ($maxWeight * 0.7) + ($avgWeight * 0.3);

            $weights[$candidateGenre] = $finalWeight;
        }

        return $weights;
    }

    /**
     * Compute related genres using GenreHierarchyService (simplified)
     */
    private function computeRelatedGenres(string $genre, int $limit): array
    {
        try {
            // Use a simple approach that builds relationships without the expensive hierarchy
            $relationships = [];

            // Get manual relationships first
            $manualRelationships = $this->getManualGenreRelationships($genre);
            foreach ($manualRelationships as $rel) {
                $relationships[] = [
                    'genre' => $rel['name'],
                    'name' => $rel['name'],
                    'match' => $rel['match'],
                    'source' => 'manual_rules'
                ];
            }

            // Get string-based relationships
            $allGenres = $this->getAllGenresFromDatabase();
            foreach ($allGenres as $compareGenre) {
                if ($compareGenre === $genre) {
                    continue;
                }

                $similarity = $this->calculateStringSimilarity($genre, $compareGenre);
                if ($similarity > 0.3) {
                    $relationships[] = [
                        'genre' => $compareGenre,
                        'name' => $compareGenre,
                        'match' => $similarity,
                        'source' => 'string_similarity'
                    ];
                }
            }

            // Remove duplicates and sort
            $relationships = $this->deduplicateRelationships($relationships);
            usort($relationships, fn($a, $b) => $b['match'] <=> $a['match']);

            $result = array_slice($relationships, 0, $limit);

            Log::debug("Found related genres", [
                'genre' => $genre,
                'relationships_count' => count($result),
                'relationships' => array_column($result, 'name')
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::warning("Related genres calculation failed", [
                'genre' => $genre,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get all genres from database
     */
    private function getAllGenresFromDatabase(): array
    {
        $cacheKey = 'all_genres_for_similarity';

        return Cache::remember($cacheKey, now()->addHours(1), function () {
            return DB::table('genres')
                ->pluck('name')
                ->toArray();
        });
    }

    /**
     * Get manual genre relationships
     */
    private function getManualGenreRelationships(string $genre): array
    {
        $relationships = [];
        $genre = strtolower($genre);

        // Define manual genre relationships
        $manualMappings = [
            'rock' => [
                ['name' => 'alternative rock', 'match' => 0.8],
                ['name' => 'indie rock', 'match' => 0.7],
                ['name' => 'hard rock', 'match' => 0.9],
                ['name' => 'classic rock', 'match' => 0.8],
                ['name' => 'pop rock', 'match' => 0.7],
            ],
            'pop' => [
                ['name' => 'pop rock', 'match' => 0.8],
                ['name' => 'indie pop', 'match' => 0.7],
                ['name' => 'dance pop', 'match' => 0.8],
                ['name' => 'electropop', 'match' => 0.7],
                ['name' => 'synth-pop', 'match' => 0.8],
            ],
            'electronic' => [
                ['name' => 'house', 'match' => 0.8],
                ['name' => 'techno', 'match' => 0.8],
                ['name' => 'ambient', 'match' => 0.7],
                ['name' => 'dubstep', 'match' => 0.7],
                ['name' => 'trance', 'match' => 0.8],
            ],
            'metal' => [
                ['name' => 'heavy metal', 'match' => 0.9],
                ['name' => 'death metal', 'match' => 0.8],
                ['name' => 'black metal', 'match' => 0.8],
                ['name' => 'thrash metal', 'match' => 0.8],
                ['name' => 'hard rock', 'match' => 0.6],
            ],
            'hip hop' => [
                ['name' => 'rap', 'match' => 0.9],
                ['name' => 'trap', 'match' => 0.8],
                ['name' => 'conscious hip hop', 'match' => 0.8],
                ['name' => 'boom bap', 'match' => 0.7],
                ['name' => 'drill', 'match' => 0.7],
            ],
            'jazz' => [
                ['name' => 'bebop', 'match' => 0.8],
                ['name' => 'smooth jazz', 'match' => 0.8],
                ['name' => 'fusion', 'match' => 0.7],
                ['name' => 'swing', 'match' => 0.8],
                ['name' => 'modern jazz', 'match' => 0.8],
            ],
            'r&b' => [
                ['name' => 'neo soul', 'match' => 0.8],
                ['name' => 'contemporary r&b', 'match' => 0.9],
                ['name' => 'funk', 'match' => 0.7],
                ['name' => 'soul', 'match' => 0.8],
            ],
            'folk' => [
                ['name' => 'indie folk', 'match' => 0.8],
                ['name' => 'americana', 'match' => 0.7],
                ['name' => 'bluegrass', 'match' => 0.7],
                ['name' => 'acoustic', 'match' => 0.8],
                ['name' => 'country', 'match' => 0.6],
            ],
            'reggae' => [
                ['name' => 'dub', 'match' => 0.8],
                ['name' => 'ska', 'match' => 0.7],
                ['name' => 'dancehall', 'match' => 0.8],
                ['name' => 'rocksteady', 'match' => 0.8],
            ],
        ];

        return $manualMappings[$genre] ?? [];
    }

    /**
     * Remove duplicate relationships
     */
    private function deduplicateRelationships(array $relationships): array
    {
        $seen = [];
        $result = [];

        foreach ($relationships as $rel) {
            $key = $rel['name'];
            if (!isset($seen[$key]) || $seen[$key] < $rel['match']) {
                $seen[$key] = $rel['match'];
                $result[$key] = $rel;
            }
        }

        return array_values($result);
    }

    /**
     * Actual computation of genre similarity (not cached) - SIMPLIFIED
     */
    private function computeGenreSimilarity(string $genre1, string $genre2): float
    {
        // Check manual mappings first
        $manual1 = $this->getManualGenreRelationships(strtolower($genre1));
        foreach ($manual1 as $rel) {
            if (strtolower($rel['name']) === strtolower($genre2)) {
                return $rel['match'];
            }
        }

        $manual2 = $this->getManualGenreRelationships(strtolower($genre2));
        foreach ($manual2 as $rel) {
            if (strtolower($rel['name']) === strtolower($genre1)) {
                return $rel['match'];
            }
        }

        // Fallback to string similarity
        return $this->calculateStringSimilarity($genre1, $genre2);
    }

    /**
     * Calculate string-based similarity as fallback
     */
    private function calculateStringSimilarity(string $genre1, string $genre2): float
    {
        $genre1Lower = strtolower($genre1);
        $genre2Lower = strtolower($genre2);

        // Check for substring relationships
        if (str_contains($genre1Lower, $genre2Lower) || str_contains($genre2Lower, $genre1Lower)) {
            return 0.6;
        }

        // Check for common words
        $words1 = explode(' ', $genre1Lower);
        $words2 = explode(' ', $genre2Lower);
        $commonWords = array_intersect($words1, $words2);

        if (!empty($commonWords)) {
            return count($commonWords) / max(count($words1), count($words2)) * 0.5;
        }

        // Levenshtein distance for very similar spellings
        $maxLen = max(strlen($genre1Lower), strlen($genre2Lower));
        if ($maxLen <= 20) { // Only for short genre names
            $distance = levenshtein($genre1Lower, $genre2Lower);
            if ($distance <= 3) {
                return max(0.0, 1.0 - ($distance / $maxLen));
            }
        }

        return 0.0;
    }

    /**
     * Find intermediate genres that connect two genres
     */
    private function findIntermediateGenres(string $sourceGenre, string $targetGenre): array
    {
        $commonGenres = $this->getCommonGenres();
        $intermediates = [];

        foreach ($commonGenres as $intermediateGenre) {
            if ($intermediateGenre === $sourceGenre || $intermediateGenre === $targetGenre) {
                continue;
            }

            $sourceToIntermediate = $this->calculateGenreSimilarity($sourceGenre, $intermediateGenre);
            $intermediateToTarget = $this->calculateGenreSimilarity($intermediateGenre, $targetGenre);

            // Good intermediate has decent connection to both
            if ($sourceToIntermediate > 0.4 && $intermediateToTarget > 0.4) {
                $intermediates[] = [
                    'genre'              => $intermediateGenre,
                    'source_similarity'  => $sourceToIntermediate,
                    'target_similarity'  => $intermediateToTarget,
                    'average_similarity' => ($sourceToIntermediate + $intermediateToTarget) / 2,
                ];
            }
        }

        // Sort by average similarity
        usort($intermediates, fn($a, $b) => $b['average_similarity'] <=> $a['average_similarity']);

        return array_slice($intermediates, 0, 3); // Top 3 intermediates
    }

    /**
     * Get common genres for comparison
     */
    private function getCommonGenres(): array
    {
        return [
            'rock',
            'pop',
            'hip hop',
            'electronic',
            'jazz',
            'classical',
            'country',
            'r&b',
            'reggae',
            'folk',
            'metal',
            'punk',
            'indie rock',
            'alternative rock',
            'hard rock',
            'classic rock',
            'house',
            'techno',
            'ambient',
            'dubstep',
            'trance',
            'drum and bass',
            'rap',
            'trap',
            'drill',
            'conscious hip hop',
            'boom bap',
            'funk',
            'soul',
            'neo soul',
            'contemporary r&b',
            'indie pop',
            'synth-pop',
            'dance pop',
            'electropop',
            'bebop',
            'smooth jazz',
            'fusion',
            'swing',
            'modern jazz',
            'heavy metal',
            'death metal',
            'black metal',
            'thrash metal',
            'indie folk',
            'americana',
            'bluegrass',
            'acoustic',
            'dub',
            'ska',
            'dancehall',
            'rocksteady',
        ];
    }

    /**
     * Clear all genre relation caches
     */
    public function clearCache(): void
    {
        Cache::forget('genre_similarity:*');
        Cache::forget('related_genres:*');
        Log::info('Genre relation calculator cache cleared');
    }

    /**
     * Apply a filter to the database query
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $field
     * @param mixed $filterConfig
     * @return void
     */
    private function applyFilter($query, string $field, $filterConfig): void
    {
        // Handle array format filter [operator, value]
        if (is_array($filterConfig) && count($filterConfig) === 2) {
            [$operator, $value] = $filterConfig;

            // Handle callable value (dynamic filters)
            if (is_callable($value)) {
                $value = $value();
            }

            // Apply appropriate filter based on operator
            switch (strtoupper($operator)) {
                case '=':
                    $query->where($field, $value);
                    break;
                case 'IN':
                    $query->whereIn($field, (array)$value);
                    break;
                case 'NOT IN':
                    $query->whereNotIn($field, (array)$value);
                    break;
                case 'BETWEEN':
                    if (is_array($value) && count($value) === 2) {
                        $query->whereBetween($field, $value);
                    }
                    break;
                default:
                    $query->where($field, $operator, $value);
            }
        } // Handle direct value comparison (equals)
        else {
            $query->where($field, $filterConfig);
        }
    }
}