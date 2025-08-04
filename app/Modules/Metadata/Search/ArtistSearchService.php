<?php

namespace App\Modules\Metadata\Search;

use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ArtistFilter as DiscogsArtistFilter;
use App\Http\Integrations\MusicBrainz\Filters\ArtistFilter as MusicBrainzArtistFilter;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Models\Artist;
use App\Modules\Metadata\Matching\MatchingStrategy;
use App\Modules\Metadata\Matching\QualityValidator;
use Illuminate\Support\Facades\Cache;
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
     * Search all sources for artist metadata with fallback strategy
     */
    public function searchAllSources(Artist $artist): array
    {
        Log::debug('Starting searchAllSources for artist', [
            'artist_id'    => $artist->id,
            'name'         => $artist->name,
            'albums_count' => $artist->albums->count(),
            'songs_count'  => $artist->songs->count(),
        ]);

        $results = [];

        // Try MusicBrainz first (unless it's various artists or circuit breaker is active)
        if (!$this->isVariousArtist($artist) && $this->canMakeMusicBrainzRequest()) {
            $results['musicbrainz'] = $this->searchMusicBrainz($artist);
        } else {
            $results['musicbrainz'] = null;
            Log::debug('Skipping MusicBrainz search', [
                'artist_id' => $artist->id,
                'reason'    => $this->isVariousArtist($artist) ? 'various_artist' : 'circuit_breaker',
            ]);
        }

        // Try Discogs (unless it's various artists or circuit breaker is active)
        if (!$this->isVariousArtist($artist) && $this->canMakeDiscogsRequest()) {
            $results['discogs'] = $this->searchDiscogs($artist);
        } else {
            $results['discogs'] = null;
            Log::debug('Skipping Discogs search', [
                'artist_id' => $artist->id,
                'reason'    => $this->isVariousArtist($artist) ? 'various_artist' : 'circuit_breaker',
            ]);
        }

        Log::debug('Search results summary', [
            'artist_id'         => $artist->id,
            'musicbrainz_found' => $results['musicbrainz'] !== null,
            'discogs_found'     => $results['discogs'] !== null,
            'musicbrainz_score' => $results['musicbrainz']['quality_score'] ?? 'N/A',
            'discogs_score'     => $results['discogs']['quality_score'] ?? 'N/A',
        ]);

        return $results;
    }

    /**
     * Check if artist is a "Various Artists" type
     */
    private function isVariousArtist(Artist $artist): bool
    {
        $variousArtistNames = [
            'various artists',
            'various',
            'compilation',
            'soundtrack',
            'va',
            'mixed',
            'unknown artist',
        ];

        return in_array(mb_strtolower($artist->name), $variousArtistNames) || $artist->isUnknown();
    }

    private function canMakeMusicBrainzRequest(): bool
    {
        // Check rate limit
        if (Cache::has('musicbrainz_rate_limit')) {
            return false;
        }

        // Check circuit breaker
        $failureKey = 'musicbrainz_failures_' . now()->format('Y-m-d-H');
        $failures = Cache::get($failureKey, 0);

        if ($failures >= 15) {
            Log::warning('MusicBrainz circuit breaker activated', [
                'failures_this_hour' => $failures,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Search MusicBrainz for artist
     */
    public function searchMusicBrainz(Artist $artist): ?array
    {
        // Skip various artists early
        if ($this->isVariousArtist($artist)) {
            Log::debug('Skipping various artist for MusicBrainz search', [
                'artist_id' => $artist->id,
                'name'      => $artist->name,
            ]);
            return null;
        }

        // Check circuit breaker
        if (!$this->canMakeMusicBrainzRequest()) {
            Log::warning('MusicBrainz requests blocked by circuit breaker', [
                'artist_id' => $artist->id,
            ]);
            return null;
        }

        try {
            $filter = new MusicBrainzArtistFilter();
            $filter->setName($artist->name);

            Log::debug('Searching MusicBrainz for artist', [
                'artist_id'    => $artist->id,
                'name'         => $artist->name,
                'search_query' => $filter->toArray(),
            ]);

            $searchResults = $this->musicBrainzClient->search->artist($filter);

            Log::debug('MusicBrainz raw search results', [
                'artist_id'        => $artist->id,
                'total_results'    => $searchResults->count(),
                'first_few_names'  => $searchResults->take(3)->pluck('name')->toArray(),
            ]);

            if ($searchResults->isEmpty()) {
                Log::info('No MusicBrainz results found', ['artist_id' => $artist->id]);
                return null;
            }

            // Limit to top 20 results for performance
            $limitedResults = $searchResults->take(20);

            // Convert search results to array format for matching strategy
            $resultsArray = $limitedResults->map(function ($artistResult) {
                return $this->convertMusicBrainzArtistToArray($artistResult);
            })->toArray();

            // Use MatchingStrategy to find the best match
            $bestMatch = $this->matchingStrategy->findBestArtistMatch($resultsArray, $artist);

            if (!$bestMatch) {
                Log::info('No suitable MusicBrainz match found after strategy filtering', ['artist_id' => $artist->id]);
                return null;
            }

            // Get detailed data for the best match with retry logic
            $detailedData = $this->getMusicBrainzDetailedDataWithRetry($bestMatch['id'], $artist->id);

            if (!$detailedData) {
                Log::warning('Failed to get detailed MusicBrainz data after retries', [
                    'artist_id' => $artist->id,
                    'mbid'      => $bestMatch['id'],
                ]);
                return null;
            }

            $qualityScore = $this->qualityValidator->scoreArtistMatch($detailedData->toArray(), $artist);

            Log::debug('MusicBrainz match completed', [
                'artist_id'         => $artist->id,
                'mbid'              => $bestMatch['id'],
                'quality_score'     => $qualityScore,
                'metadata_keys'     => array_keys($detailedData->toArray()),
                'processed_results' => count($resultsArray),
            ]);

            return [
                'source'                  => 'musicbrainz',
                'data'                    => $detailedData->toArray(),
                'quality_score'           => $qualityScore,
                'search_results_count'    => $searchResults->count(),
                'processed_results_count' => count($resultsArray),
                'best_match'              => $bestMatch,
            ];

        } catch (\Exception $e) {
            Log::error('MusicBrainz artist search failed', [
                'artist_id' => $artist->id,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            // Record the failure
            $this->recordMusicBrainzFailure();

            return null;
        }
    }

    /**
     * Convert MusicBrainz artist object to array format expected by MatchingStrategy
     */
    private function convertMusicBrainzArtistToArray($artist): array
    {
        return [
            'id'             => $artist->id,
            'name'           => $artist->name,
            'type'           => $artist->type ?? null,
            'country'        => $artist->country ?? null,
            'life-span'      => $artist->life_span ?? null,
            'disambiguation' => $artist->disambiguation ?? '',
            'tags'           => $artist->tags ?? [],
            'score'          => $artist->score ?? 0,
            'sort-name'      => $artist->sort_name ?? null,
            'gender'         => $artist->gender ?? null,
            'aliases'        => $artist->aliases ?? [],
        ];
    }

    /**
     * Get MusicBrainz detailed data with retry logic
     */
    private function getMusicBrainzDetailedDataWithRetry(string $artistId, int $localArtistId, int $maxRetries = 3): mixed
    {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            try {
                Log::debug('Attempting MusicBrainz lookup', [
                    'artist_id' => $localArtistId,
                    'mbid'      => $artistId,
                    'attempt'   => $attempt,
                ]);

                // Add timeout and better error handling
                $startTime = microtime(true);
                $detailedData = $this->musicBrainzClient->lookup->artist($artistId);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                if ($detailedData) {
                    Log::debug('MusicBrainz lookup successful', [
                        'artist_id'   => $localArtistId,
                        'mbid'        => $artistId,
                        'attempt'     => $attempt,
                        'duration_ms' => $duration,
                    ]);
                    return $detailedData;
                } else {
                    Log::warning('MusicBrainz lookup returned null', [
                        'artist_id'   => $localArtistId,
                        'mbid'        => $artistId,
                        'attempt'     => $attempt,
                        'duration_ms' => $duration,
                    ]);
                }

            } catch (\Exception $e) {
                Log::warning('MusicBrainz lookup attempt failed', [
                    'artist_id'   => $localArtistId,
                    'mbid'        => $artistId,
                    'attempt'     => $attempt,
                    'error'       => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);

                // Check if it's a rate limit error
                if (str_contains(mb_strtolower($e->getMessage()), 'rate limit') ||
                    str_contains(mb_strtolower($e->getMessage()), '429')) {
                    Log::warning('MusicBrainz rate limit detected', [
                        'artist_id' => $localArtistId,
                        'mbid'      => $artistId,
                    ]);

                    // Cache rate limit for longer period
                    Cache::put('musicbrainz_rate_limit', true, now()->addMinutes(5));
                    break;
                }

                // Check if it's a network timeout
                if (str_contains(mb_strtolower($e->getMessage()), 'timeout') ||
                    str_contains(mb_strtolower($e->getMessage()), 'curl')) {
                    Log::warning('MusicBrainz network issue detected', [
                        'artist_id' => $localArtistId,
                        'mbid'      => $artistId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            if ($attempt < $maxRetries) {
                $waitTime = min($attempt * 2, 10); // Cap at 10 seconds
                Log::debug('Waiting before MusicBrainz retry', [
                    'artist_id'    => $localArtistId,
                    'wait_seconds' => $waitTime,
                    'next_attempt' => $attempt + 1,
                ]);
                sleep($waitTime);
            }

            $attempt++;
        }

        // Record the failure for circuit breaker
        $this->recordMusicBrainzFailure();

        return null;
    }

    private function recordMusicBrainzFailure(): void
    {
        $failureKey = 'musicbrainz_failures_' . now()->format('Y-m-d-H');
        $failures = Cache::get($failureKey, 0);
        Cache::put($failureKey, $failures + 1, now()->addHour());

        Log::debug('Recorded MusicBrainz failure', [
            'total_failures_this_hour' => $failures + 1,
        ]);
    }

    private function canMakeDiscogsRequest(): bool
    {
        // Check if we're currently rate limited
        $rateLimitKey = 'discogs_rate_limit';

        // Check if we have too many recent failures (circuit breaker)
        $failureKey = 'discogs_failures_' . now()->format('Y-m-d-H');
        $failures = Cache::get($failureKey, 0);

        if ($failures >= 10) {
            Log::warning('Discogs circuit breaker activated', [
                'failures_this_hour' => $failures,
            ]);
            return false;
        }

        return !Cache::has($rateLimitKey);
    }

    /**
     * Search Discogs for artist with improved error handling
     */
    public function searchDiscogs(Artist $artist): ?array
    {
        if ($this->isVariousArtist($artist)) {
            Log::debug('Skipping various artist for Discogs search', [
                'artist_id' => $artist->id,
                'name'      => $artist->name,
            ]);
            return null;
        }

        if (!$this->canMakeDiscogsRequest()) {
            Log::warning('Discogs rate limit reached, skipping search', [
                'artist_id' => $artist->id,
            ]);
            return null;
        }

        try {
            $filter = new DiscogsArtistFilter();
            $filter->setTitle($artist->name); // Note: Discogs uses 'title' for artist names

            Log::debug('Searching Discogs for artist', [
                'artist_id'    => $artist->id,
                'name'         => $artist->name,
                'search_query' => $filter->toArray(),
            ]);

            $searchResults = $this->discogsClient->search->artist($filter);

            Log::debug('Discogs raw search results', [
                'artist_id'        => $artist->id,
                'total_results'    => $searchResults->count(),
                'first_few_names'  => $searchResults->take(3)->pluck('title')->toArray(),
            ]);

            if ($searchResults->isEmpty()) {
                Log::info('No Discogs results found', ['artist_id' => $artist->id]);
                return null;
            }

            // Limit to top 15 results for performance (Discogs typically has fewer duplicates)
            $limitedResults = $searchResults->take(15);

            // Convert search results to array format for matching strategy
            $resultsArray = $limitedResults->map(function ($artistResult) {
                return $this->convertDiscogsArtistToArray($artistResult);
            })->toArray();

            // Use MatchingStrategy to find the best match
            $bestMatch = $this->matchingStrategy->findBestArtistMatch($resultsArray, $artist);

            if (!$bestMatch) {
                Log::info('No suitable Discogs match found after strategy filtering', ['artist_id' => $artist->id]);
                return null;
            }

            // Get detailed data for the best match with retry logic
            $detailedData = $this->getDiscogsDetailedDataWithRetry($bestMatch['id'], $artist->id);

            if (!$detailedData) {
                Log::warning('Failed to get detailed Discogs data after retries', [
                    'artist_id'  => $artist->id,
                    'discogs_id' => $bestMatch['id'],
                ]);
                return null;
            }

            $qualityScore = $this->qualityValidator->scoreArtistMatch($detailedData->toArray(), $artist);

            Log::debug('Discogs match completed', [
                'artist_id'         => $artist->id,
                'discogs_id'        => $bestMatch['id'],
                'quality_score'     => $qualityScore,
                'metadata_keys'     => array_keys($detailedData->toArray()),
                'processed_results' => count($resultsArray),
            ]);

            return [
                'source'                  => 'discogs',
                'data'                    => $detailedData->toArray(),
                'quality_score'           => $qualityScore,
                'search_results_count'    => $searchResults->count(),
                'processed_results_count' => count($resultsArray),
                'pagination'              => $this->discogsClient->search->getPagination(),
                'best_match'              => $bestMatch,
            ];

        } catch (\Exception $e) {
            Log::error('Discogs artist search failed', [
                'artist_id' => $artist->id,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Convert Discogs artist object to array format expected by MatchingStrategy
     */
    private function convertDiscogsArtistToArray($artist): array
    {
        return [
            'id'      => $artist->id,
            'name'    => $artist->title ?? $artist->name ?? '',
            'profile' => $artist->profile ?? null,
            'images'  => $artist->images ?? [],
            'urls'    => $artist->urls ?? [],
            'aliases' => $artist->aliases ?? [],
        ];
    }

    /**
     * Get Discogs detailed data with retry logic
     */
    private function getDiscogsDetailedDataWithRetry(string $artistId, int $localArtistId, int $maxRetries = 2): mixed
    {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            try {
                Log::debug('Attempting Discogs lookup', [
                    'artist_id'  => $localArtistId,
                    'discogs_id' => $artistId,
                    'attempt'    => $attempt,
                ]);

                $detailedData = $this->discogsClient->lookup->artist($artistId);

                if ($detailedData) {
                    Log::debug('Discogs lookup successful', [
                        'artist_id'  => $localArtistId,
                        'discogs_id' => $artistId,
                        'attempt'    => $attempt,
                    ]);
                    return $detailedData;
                }

            } catch (\Exception $e) {
                Log::warning('Discogs lookup attempt failed', [
                    'artist_id'  => $localArtistId,
                    'discogs_id' => $artistId,
                    'attempt'    => $attempt,
                    'error'      => $e->getMessage(),
                ]);

                // Don't retry on 500 errors immediately - they're usually server-side issues
                if (str_contains($e->getMessage(), '500') || str_contains($e->getMessage(), 'Internal Server Error')) {
                    Log::info('Skipping retry for Discogs server error', [
                        'artist_id'  => $localArtistId,
                        'discogs_id' => $artistId,
                    ]);
                    break;
                }
            }

            if ($attempt < $maxRetries) {
                $waitTime = $attempt * 3; // Progressive backoff: 3s, 6s
                Log::debug('Waiting before Discogs retry', [
                    'artist_id'    => $localArtistId,
                    'wait_seconds' => $waitTime,
                ]);
                sleep($waitTime);
            }

            $attempt++;
        }

        // Record the failure for circuit breaker
        $this->recordDiscogsFailure();

        return null;
    }

    private function recordDiscogsFailure(): void
    {
        $failureKey = 'discogs_failures_' . now()->format('Y-m-d-H');
        $failures = Cache::get($failureKey, 0);
        Cache::put($failureKey, $failures + 1, now()->addHour());

        Log::debug('Recorded Discogs failure', [
            'total_failures_this_hour' => $failures + 1,
        ]);
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
            if ($this->canMakeMusicBrainzRequest()) {
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
            }

            // Try Discogs
            if ($this->canMakeDiscogsRequest()) {
                $filter = new DiscogsArtistFilter();
                $filter->setTitle($variation);

                try {
                    $searchResults = $this->discogsClient->search->artist($filter);
                    if (!$searchResults->isEmpty()) {
                        foreach ($searchResults->take(3) as $result) {
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
            return $result;
        }, $uniqueResults);

        // Sort by quality score descending
        usort($scoredResults, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);

        return [
            'total_results' => count($scoredResults),
            'variations_tried' => $variations,
            'results' => $scoredResults,
            'best_match' => !empty($scoredResults) ? $scoredResults[0] : null,
        ];
    }

    /**
     * Generate artist name variations for fuzzy matching
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