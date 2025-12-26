<?php

namespace App\Modules\Metadata;

use App\Format\TextSimilarity;
use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter;
use App\Http\Integrations\LastFm\LastFmClient;
use App\Models\Genre;
use App\Models\User;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Exception;
use Psr\Log\LoggerInterface;

class GenreHierarchyService
{
    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    private LastFmClient $lastFmClient;

    public function __construct(
        private readonly DiscogsClient $discogsClient,
        LastFmClient $lastFmClient,
        private readonly TextSimilarity $textSimilarity,
    )
    {
        $user = User::first();
        $this->lastFmClient = $user ? $lastFmClient->forUser($user) : $lastFmClient;
    }

    /**
     * Build genre hierarchy using batch processing
     */
    public function buildGenreHierarchyBatch(array $genres, int $batchSize = 5): array
    {
        $hierarchy = [];
        $batches = array_chunk($genres, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info("Processing batch $batchIndex", ['genres' => $batch]);

            foreach ($batch as $genre) {
                $genre = trim($genre);

                // Get LastFM data
                try {
                    $tagInfo = $this->lastFmClient->tags->getTagInfo($genre);
                } catch (Exception $e) {
                    $this->logger->warning("LastFM failed for $genre", ['error' => $e->getMessage()]);
                    $tagInfo = [];
                }

                // Get Discogs data using search-only approach
                $discogsData = $this->getDiscogsGenreData($genre);

                // Get MusicBrainz data
                $musicBrainzData = $this->getMusicBrainzGenreData($genre);

                $this->logger->info("Processed genre: $genre", [
                    'lastfm_popularity'    => $tagInfo['reach'] ?? 0,
                    'discogs_styles_count' => count($discogsData['related_styles']),
                    'discogs_genres_count' => count($discogsData['related_genres']),
                    'discogs_releases'     => $discogsData['release_count'],
                    'musicbrainz_found'    => $musicBrainzData['found'],
                    'musicbrainz_mbid'     => $musicBrainzData['mbid'],
                ]);

                $hierarchy[$genre] = [
                    'lastfm'      => [
                        'info'        => $tagInfo,
                        'similar'     => [],
                        'popularity'  => $tagInfo['reach'] ?? 0,
                        'description' => $tagInfo['wiki']['summary'] ?? null,
                    ],
                    'discogs'     => $discogsData,
                    'musicbrainz' => $musicBrainzData,
                ];
            }
        }

        return $this->organizeHierarchyWithAlternatives($hierarchy, $genres);
    }

    /**
     * Get MusicBrainz genre data from database
     */
    private function getMusicBrainzGenreData(string $genre): array
    {
        try {
            $genreRecord = Genre::where('name', $genre)->first();

            if (!$genreRecord) {
                return [
                    'mbid'           => null,
                    'canonical_name' => null,
                    'found'          => false,
                ];
            }

            return [
                'mbid'           => $genreRecord->mbid,
                'canonical_name' => $genreRecord->name,
                'found'          => !empty($genreRecord->mbid),
            ];
        } catch (\Exception $e) {
            $this->logger->warning("MusicBrainz genre lookup failed for {$genre}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'mbid'           => null,
                'canonical_name' => null,
                'found'          => false,
            ];
        }
    }

    /**
     * Get Discogs genre data using search results only (avoids problematic lookup calls)
     */
    private function getDiscogsGenreData(string $genre): array
    {
        try {
            $filter = new ReleaseFilter(genre: $genre, per_page: 50);
            $searchResults = $this->discogsClient->search->releaseRaw($filter);

            // Handle rate limiting or API failures
            if ($searchResults === null) {
                $this->logger->warning("Discogs search returned null (rate limited or failed) for {$genre}");
                return [
                    'related_styles' => [],
                    'related_genres' => [],
                    'release_count'  => 0,
                    'method'         => 'failed',
                ];
            }

            $allStyles = [];
            $allGenres = [];

            // Extract genres and styles directly from search results
            foreach ($searchResults['results'] ?? [] as $release) {
                if (isset($release['genre']) && is_array($release['genre'])) {
                    $allGenres = array_merge($allGenres, $release['genre']);
                }

                if (isset($release['style']) && is_array($release['style'])) {
                    $allStyles = array_merge($allStyles, $release['style']);
                }
            }

            return [
                'related_styles' => array_unique($allStyles),
                'related_genres' => array_unique($allGenres),
                'release_count'  => count($searchResults['results'] ?? []),
                'method'         => 'search_only',
            ];

        } catch (Exception $e) {
            $this->logger->warning("Discogs search failed for {$genre}", ['error' => $e->getMessage()]);
            return [
                'related_styles' => [],
                'related_genres' => [],
                'release_count'  => 0,
                'method'         => 'failed',
            ];
        }
    }

    /**
     * Organize genre hierarchy using multiple relationship strategies
     */
    private function organizeHierarchyWithAlternatives(array $genreData, array $allGenres): array
    {
        $this->logger->info('Organizing hierarchy with relationship methods', ['genre_count' => count($genreData)]);

        $organized = [
            'root_genres'        => [],
            'subgenres'          => [],
            'relationships'      => [],
            'all_similar_genres' => [],
            'genre_details'      => [],
            'similarity_matrix'  => [], // NEW: Normalized similarity data
        ];

        // Build relationships for each genre
        foreach ($genreData as $genre => $data) {
            $relationships = $this->buildGenreRelationships($genre, $allGenres, $genreData);

            $organized['genre_details'][$genre] = [
                'has_similar'    => !empty($relationships),
                'similar_count'  => count($relationships),
                'similar_names'  => array_column($relationships, 'name'),
                'discogs_styles' => $data['discogs']['related_styles'] ?? [],
                'popularity'     => $data['lastfm']['popularity'] ?? 0,
                'relationships'  => $relationships,
            ];

            // Add relationships
            foreach ($relationships as $similar) {
                $organized['all_similar_genres'][] = $similar['name'];
                $organized['relationships'][] = [
                    'parent'     => $similar['name'],
                    'child'      => $genre,
                    'similarity' => $similar['match'],
                    'source'     => $similar['source'],
                ];
            }

            // Mark as root genre if no relationships found
            if (empty($relationships)) {
                $organized['root_genres'][] = $genre;
            }
        }

        // Build similarity matrix for fast lookup
        $organized['similarity_matrix'] = $this->buildSimilarityMatrix($genreData, $allGenres, $organized['genre_details']);

        // Identify subgenres (genres that are children of others)
        foreach ($organized['relationships'] as $relationship) {
            if (!in_array($relationship['child'], $organized['subgenres'], true)) {
                $organized['subgenres'][] = $relationship['child'];
            }
        }

        return $organized;
    }

    /**
     * Build relationships between genres using multiple strategies
     */
    private function buildGenreRelationships(string $genre, array $allGenres, array $genreData): array
    {
        $relationships = [];

        // Strategy 1: String-based relationships
        $stringRelationships = $this->getStringBasedRelationships($genre, $allGenres);
        foreach ($stringRelationships as $rel) {
            $relationships[] = array_merge($rel, ['source' => 'string_similarity']);
        }

        // Strategy 2: Discogs style relationships
        $discogsRelationships = $this->getDiscogsStyleRelationships($genre, $allGenres, $genreData);
        foreach ($discogsRelationships as $rel) {
            $relationships[] = array_merge($rel, ['source' => 'discogs_styles']);
        }

        // Strategy 3: Manual genre hierarchy rules
        $manualRelationships = $this->getManualGenreRelationships($genre);
        foreach ($manualRelationships as $rel) {
            if (in_array($rel['name'], $allGenres, true)) {
                $relationships[] = array_merge($rel, ['source' => 'manual_rules']);
            }
        }

        // Remove duplicates and sort by similarity
        $relationships = $this->deduplicateRelationships($relationships);
        usort($relationships, fn($a, $b) => $b['match'] <=> $a['match']);

        return array_slice($relationships, 0, 5); // Top 5 relationships
    }

    /**
     * Find relationships based on string patterns
     */
    private function getStringBasedRelationships(string $genre, array $allGenres): array
    {
        $relationships = [];

        foreach ($allGenres as $otherGenre) {
            if ($genre === $otherGenre) {
                continue;
            }

            $similarity = 0;

            // Check for direct subgenre patterns
            if (str_contains($otherGenre, $genre)) {
                $similarity = 0.9; // e.g., "rock" -> "hard rock"
            } else if (str_contains($genre, $otherGenre)) {
                $similarity = 0.8; // e.g., "hard rock" -> "rock"
            } else {
                // Check for common words
                $genreWords = explode(' ', strtolower($genre));
                $otherWords = explode(' ', strtolower($otherGenre));
                $commonWords = array_intersect($genreWords, $otherWords);

                if (!empty($commonWords)) {
                    $similarity = count($commonWords) / max(count($genreWords), count($otherWords));
                }
            }

            if ($similarity > 0.3) {
                $relationships[] = [
                    'name'  => $otherGenre,
                    'match' => $similarity,
                ];
            }
        }

        return $relationships;
    }

    /**
     * Find relationships based on shared Discogs styles
     */
    private function getDiscogsStyleRelationships(string $genre, array $allGenres, array $genreData): array
    {
        $relationships = [];
        $genreStyles = $genreData[$genre]['discogs']['related_styles'] ?? [];

        foreach ($allGenres as $otherGenre) {
            if ($genre === $otherGenre) continue;

            $otherStyles = $genreData[$otherGenre]['discogs']['related_styles'] ?? [];
            $commonStyles = array_intersect($genreStyles, $otherStyles);

            if (!empty($commonStyles)) {
                $similarity = count($commonStyles) / max(count($genreStyles), count($otherStyles), 1);
                if ($similarity > 0.2) {
                    $relationships[] = [
                        'name'  => $otherGenre,
                        'match' => $similarity,
                    ];
                }
            }
        }

        return $relationships;
    }

    /**
     * Get manual genre hierarchy rules
     */
    private function getManualGenreRelationships(string $genre): array
    {
        $manualRules = [
            'rock'       => ['alternative rock', 'hard rock', 'indie rock', 'punk rock', 'classic rock'],
            'electronic' => ['house', 'techno', 'ambient', 'dubstep', 'trance'],
            'hip hop'    => ['rap', 'trap', 'drill', 'boom bap', 'conscious hip hop'],
            'jazz'       => ['bebop', 'smooth jazz', 'fusion', 'swing', 'modern jazz'],
            'pop'        => ['dance pop', 'synth-pop', 'indie pop', 'electropop'],
            'metal'      => ['heavy metal', 'death metal', 'black metal', 'thrash metal', 'power metal'],
            'folk'       => ['indie folk', 'american folk', 'celtic folk', 'acoustic folk'],
            'country'    => ['alt-country', 'bluegrass', 'americana', 'country rock'],
            'r&b'        => ['neo soul', 'contemporary r&b', 'funk', 'soul'],
            'reggae'     => ['dub', 'ska', 'rocksteady', 'dancehall'],
        ];

        $lowerGenre = strtolower($genre);
        $relationships = [];

        // Direct matches (parent -> children)
        if (isset($manualRules[$lowerGenre])) {
            foreach ($manualRules[$lowerGenre] as $related) {
                $relationships[] = [
                    'name'  => $related,
                    'match' => 0.85,
                ];
            }
        }

        // Reverse matches (child -> parent)
        foreach ($manualRules as $parent => $children) {
            if (in_array($lowerGenre, array_map('mb_strtolower', $children), true)) {
                $relationships[] = [
                    'name'  => $parent,
                    'match' => 0.9,
                ];
            }
        }

        return $relationships;
    }

    /**
     * Remove duplicate relationships, keeping the highest similarity score
     */
    private function deduplicateRelationships(array $relationships): array
    {
        $unique = [];
        $seen = [];

        foreach ($relationships as $rel) {
            $key = strtolower($rel['name']);
            if (!isset($seen[$key]) || $seen[$key] < $rel['match']) {
                $seen[$key] = $rel['match'];
                $unique[$key] = $rel;
            }
        }

        return array_values($unique);
    }

    /**
     * Build a normalized similarity matrix for fast lookup
     */
    private function buildSimilarityMatrix(array $genreData, array $allGenres, array $genreDetails): array
    {
        $matrix = [];

        foreach ($allGenres as $genre1) {
            $matrix[$genre1] = [];

            foreach ($allGenres as $genre2) {
                if ($genre1 === $genre2) {
                    $matrix[$genre1][$genre2] = 1.0; // Self-similarity
                    continue;
                }

                // Check if genre2 is in genre1's relationships
                $similarity = 0.0;
                $relationships = $genreDetails[$genre1]['relationships'] ?? [];

                foreach ($relationships as $relationship) {
                    if ($relationship['name'] === $genre2) {
                        $similarity = $relationship['match'];
                        break;
                    }
                }

                // If no direct relationship, calculate based on shared characteristics
                if ($similarity === 0.0) {
                    $similarity = $this->calculateFallbackSimilarity($genre1, $genre2, $genreData);
                }

                $matrix[$genre1][$genre2] = $similarity;
            }
        }

        return $matrix;
    }

    /**
     * Calculate fallback similarity when no direct relationship exists
     */
    private function calculateFallbackSimilarity(string $genre1, string $genre2, array $genreData): float
    {
        // Check shared Discogs styles
        $genre1Styles = $genreData[$genre1]['discogs']['related_styles'] ?? [];
        $genre2Styles = $genreData[$genre2]['discogs']['related_styles'] ?? [];

        if (!empty($genre1Styles) && !empty($genre2Styles)) {
            $commonStyles = array_intersect($genre1Styles, $genre2Styles);
            if (!empty($commonStyles)) {
                return count($commonStyles) / max(count($genre1Styles), count($genre2Styles));
            }
        }

        // String-based similarity as final fallback
        return $this->calculateStringSimilarity($genre1, $genre2);
    }

    /**
     * Calculate string-based similarity using TextSimilarity
     */
    private function calculateStringSimilarity(string $genre1, string $genre2): float
    {
        // Use TextSimilarity for more accurate comparison
        // Returns score 0-100, normalize to 0-1 for consistency
        $similarity = $this->textSimilarity->calculateSimilarity($genre1, $genre2);
        return $similarity / 100.0;
    }

    /**
     * Simplified approach without Discogs data for testing
     */
    public function buildGenreHierarchySimple(array $genres): array
    {
        $hierarchy = [];

        foreach ($genres as $genre) {
            $genre = trim($genre);

            // Get LastFM data only
            try {
                $tagInfo = $this->lastFmClient->tags->getTagInfo($genre);
            } catch (Exception $e) {
                $this->logger->warning("LastFM failed for {$genre}", ['error' => $e->getMessage()]);
                $tagInfo = [];
            }

            // Get MusicBrainz data from database
            $musicBrainzData = $this->getMusicBrainzGenreData($genre);

            $hierarchy[$genre] = [
                'lastfm'      => [
                    'info'        => $tagInfo,
                    'similar'     => [],
                    'popularity'  => $tagInfo['reach'] ?? 0,
                    'description' => $tagInfo['wiki']['summary'] ?? null,
                ],
                'discogs'     => [
                    'related_styles' => [],
                    'related_genres' => [],
                    'release_count'  => 0,
                ],
                'musicbrainz' => $musicBrainzData,
            ];
        }

        return $this->organizeHierarchyWithAlternatives($hierarchy, $genres);
    }

    /**
     * Get similarity score between two genres from the hierarchy data
     */
    public function getGenreSimilarity(array $hierarchyData, string $genre1, string $genre2): float
    {
        if ($genre1 === $genre2) {
            return 1.0;
        }

        // Check the similarity matrix first
        return $hierarchyData['similarity_matrix'][$genre1][$genre2]
            ?? $hierarchyData['similarity_matrix'][$genre2][$genre1]
            ?? $this->calculateStringSimilarity($genre1, $genre2);
    }
}