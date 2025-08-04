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
     * Search for artist with fuzzy matching
     * Now made public to be used by external services
     */
    public function searchFuzzy(Artist $artist): array
    {
        $results = [];

        // Try variations of the artist name
        $variations = $this->generateArtistNameVariations($artist->name);

        foreach ($variations as $variation) {
            // Try MusicBrainz
            $filter = new MusicBrainzArtistFilter();
            $filter->setName($variation);

            try {
                $searchResults = $this->musicBrainzClient->search->artist($filter);
                if (!$searchResults->isEmpty()) {
                    $results['musicbrainz_' . $variation] = $searchResults->take(3);
                }
            } catch (\Exception $e) {
                Log::debug('Fuzzy search variation failed', [
                    'variation' => $variation,
                    'error'     => $e->getMessage(),
                ]);
            }

            // Try Discogs
            $discogsFilter = new DiscogsArtistFilter();
            $discogsFilter->setTitle($variation);

            try {
                $discogsResults = $this->discogsClient->search->artist($discogsFilter);
                if (!$discogsResults->isEmpty()) {
                    $results['discogs_' . $variation] = $discogsResults->take(3);
                }
            } catch (\Exception $e) {
                Log::debug('Discogs fuzzy search variation failed', [
                    'variation' => $variation,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $results;
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