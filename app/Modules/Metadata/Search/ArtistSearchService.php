<?php

namespace App\Modules\Metadata\Search;

use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ArtistFilter as DiscogsArtistFilter;
use App\Http\Integrations\MusicBrainz\Filters\ArtistFilter as MusicBrainzArtistFilter;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Models\Artist;
use App\Modules\Metadata\Matching\{QualityValidator};
use App\Modules\Metadata\Matching\MatchingStrategy;
use Illuminate\Support\Facades\Log;

class ArtistSearchService
{
    public function __construct(
        private readonly MusicBrainzClient $musicBrainzClient,
        private readonly DiscogsClient     $discogsClient,
        private readonly MatchingStrategy  $matchingStrategy,
        private readonly QualityValidator  $qualityValidator,
    )
    {
    }

    /**
     * Search all sources for artist metadata
     */
    public function searchAllSources(Artist $artist): array
    {
        return [
            'musicbrainz' => $this->searchMusicBrainz($artist),
            'discogs'     => $this->searchDiscogs($artist),
        ];
    }

    /**
     * Search MusicBrainz for artist
     */
    public function searchMusicBrainz(Artist $artist): ?array
    {
        try {
            $filter = new MusicBrainzArtistFilter();
            $filter->setName($artist->name);

            Log::debug('Searching MusicBrainz for artist', [
                'artist_id' => $artist->id,
                'name'      => $artist->name,
            ]);

            $searchResults = $this->musicBrainzClient->search->artist($filter);

            if ($searchResults->isEmpty()) {
                return null;
            }

            $relevantResults = $searchResults
                ->filter(fn($artistModel) => $this->isRelevantMatch($artistModel, $artist))
                ->sortByDesc(fn($artistModel) => $this->calculateRelevanceScore($artistModel, $artist))
                ->take(5);

            if ($relevantResults->isEmpty()) {
                return null;
            }

            $bestMatch = $relevantResults->first();
            $detailedData = $this->musicBrainzClient->lookup->artist($bestMatch->id);

            return [
                'source'               => 'musicbrainz',
                'data'                 => $detailedData->toArray(),
                'quality_score'        => $this->qualityValidator->scoreArtistMatch($detailedData->toArray(), $artist),
                'search_results_count' => $searchResults->count(),
            ];

        } catch (\Exception $e) {
            Log::warning('MusicBrainz artist search failed', [
                'artist_id' => $artist->id,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isRelevantMatch($artistModel, Artist $artist): bool
    {
        if (empty($artistModel->name)) {
            return false;
        }

        $similarity = similar_text(
            mb_strtolower($artistModel->name),
            mb_strtolower($artist->name),
        );

        return $similarity > 70;
    }

    private function calculateRelevanceScore($artistModel, Artist $artist): float
    {
        $baseScore = similar_text(mb_strtolower($artistModel->name), mb_strtolower($artist->name));

        // Bonus for exact matches
        if (mb_strtolower($artistModel->name) === mb_strtolower($artist->name)) {
            $baseScore += 50;
        }

        // Bonus for disambiguation matches (like "Artist (band)")
        if (str_contains(mb_strtolower($artistModel->name), mb_strtolower($artist->name))) {
            $baseScore += 20;
        }

        return $baseScore;
    }

    /**
     * Search Discogs for artist
     */
    public function searchDiscogs(Artist $artist): ?array
    {
        try {
            $filter = new DiscogsArtistFilter();
            $filter->setTitle($artist->name);

            Log::debug('Searching Discogs for artist', [
                'artist_id' => $artist->id,
                'name'      => $artist->name,
            ]);

            $searchResults = $this->discogsClient->search->artist($filter);

            if ($searchResults->isEmpty()) {
                return null;
            }

            $relevantResults = $searchResults
                ->filter(fn($artistModel) => $this->isRelevantDiscogsMatch($artistModel, $artist))
                ->sortByDesc(fn($artistModel) => $this->calculateDiscogsRelevanceScore($artistModel, $artist))
                ->take(5);

            if ($relevantResults->isEmpty()) {
                return null;
            }

            $bestMatch = $relevantResults->first();
            $detailedData = $this->discogsClient->lookup->artist($bestMatch->id);

            return [
                'source'               => 'discogs',
                'data'                 => $detailedData->toArray(),
                'quality_score'        => $this->qualityValidator->scoreArtistMatch($detailedData->toArray(), $artist),
                'search_results_count' => $searchResults->count(),
                'pagination'           => $this->discogsClient->search->getPagination(),
            ];

        } catch (\Exception $e) {
            Log::warning('Discogs artist search failed', [
                'artist_id' => $artist->id,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isRelevantDiscogsMatch($artistModel, Artist $artist): bool
    {
        if (empty($artistModel->title)) {
            return false;
        }

        $similarity = similar_text(
            mb_strtolower($artistModel->title),
            mb_strtolower($artist->name),
        );

        return $similarity > 70;
    }

    private function calculateDiscogsRelevanceScore($artistModel, Artist $artist): float
    {
        $baseScore = similar_text(mb_strtolower($artistModel->title), mb_strtolower($artist->name));

        // Bonus for exact matches
        if (mb_strtolower($artistModel->title) === mb_strtolower($artist->name)) {
            $baseScore += 50;
        }

        return $baseScore;
    }


    /**
     * Search for artist with fuzzy matching - returns structured results
     */
    public function searchFuzzy(Artist $artist): array
    {
        $allResults = [];
        $variations = $this->generateArtistNameVariations($artist->name);

        foreach ($variations as $variation) {
            // Try MusicBrainz
            $filter = new MusicBrainzArtistFilter();
            $filter->setName($variation);

            try {
                $searchResults = $this->musicBrainzClient->search->artist($filter);
                if (!$searchResults->isEmpty()) {
                    foreach ($searchResults->take(3) as $result) {
                        $allResults[] = [
                            'id' => $result->id,
                            'source' => 'musicbrainz',
                            'variation_used' => $variation,
                            'data' => $this->convertMusicBrainzArtistToArray($result),
                            'raw_result' => $result,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Fuzzy search variation failed', [
                    'source' => 'musicbrainz',
                    'variation' => $variation,
                    'error' => $e->getMessage(),
                ]);
            }

            // Try Discogs
            $discogsFilter = new DiscogsArtistFilter();
            $discogsFilter->setTitle($variation);

            try {
                $discogsResults = $this->discogsClient->search->artist($discogsFilter);
                if (!$discogsResults->isEmpty()) {
                    foreach ($discogsResults->take(3) as $result) {
                        $allResults[] = [
                            'id' => $result->id,
                            'source' => 'discogs',
                            'variation_used' => $variation,
                            'data' => $this->convertDiscogsArtistToArray($result),
                            'raw_result' => $result,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Discogs fuzzy search variation failed', [
                    'source' => 'discogs',
                    'variation' => $variation,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Remove duplicates based on source + id
        $uniqueResults = collect($allResults)
            ->unique(fn($result) => $result['source'] . '_' . $result['id'])
            ->values()
            ->toArray();

        // Score and sort results
        $scoredResults = array_map(function ($result) use ($artist) {
            $qualityScore = $this->qualityValidator->scoreArtistMatch($result['data'], $artist);
            $result['quality_score'] = $qualityScore;
            $result['is_high_confidence'] = $this->qualityValidator->isHighConfidenceArtistMatch(
                $result['data'],
                $artist,
                $qualityScore
            );
            return $result;
        }, $uniqueResults);

        // Sort by quality score descending
        usort($scoredResults, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);

        return [
            'total_results' => count($scoredResults),
            'variations_tried' => $variations,
            'results' => $scoredResults,
            'best_match' => !empty($scoredResults) ? $scoredResults[0] : null,
            'high_confidence_matches' => array_filter($scoredResults, fn($result) => $result['is_high_confidence']),
        ];
    }

    /**
     * Convert MusicBrainz artist to standardized array format
     */
    private function convertMusicBrainzArtistToArray($artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name ?? '',
            'type' => $artist->type ?? null,
            'country' => $artist->country ?? null,
            'life-span' => $artist->life_span ?? null,
            'disambiguation' => $artist->disambiguation ?? '',
            'tags' => $artist->tags ?? [],
            'score' => $artist->score ?? 0,
        ];
    }

    /**
     * Convert Discogs artist to standardized array format
     */
    private function convertDiscogsArtistToArray($artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->title ?? $artist->name ?? '',
            'profile' => $artist->profile ?? null,
            'images' => $artist->images ?? [],
            'urls' => $artist->urls ?? [],
        ];
    }

    /**
     * Generate artist name variations for fuzzy matching
     * Enhanced with more variation types
     */
    private function generateArtistNameVariations(string $name): array
    {
        $variations = [$name];

        // Remove common prefixes/suffixes
        $cleanName = preg_replace('/^(the\s+|a\s+|an\s+)/i', '', $name);
        if ($cleanName !== $name) {
            $variations[] = $cleanName;
        }

        // Add "The" prefix if not present
        if (!preg_match('/^the\s+/i', $name)) {
            $variations[] = 'The ' . $name;
        }

        // Replace special characters
        $normalized = preg_replace('/[^\w\s]/', '', $name);
        if ($normalized !== $name) {
            $variations[] = $normalized;
        }

        // Replace & with "and" and vice versa
        if (str_contains($name, '&')) {
            $variations[] = str_replace('&', 'and', $name);
        }
        if (str_contains($name, 'and')) {
            $variations[] = str_replace('and', '&', $name);
        }

        // Remove disambiguation info like "Artist (band)", "Artist (US)", etc.
        $withoutDisambiguation = preg_replace('/\s*\([^)]*\)/', '', $name);
        if ($withoutDisambiguation !== $name) {
            $variations[] = trim($withoutDisambiguation);
        }

        // Remove duplicates and return
        return array_unique($variations);
    }
}