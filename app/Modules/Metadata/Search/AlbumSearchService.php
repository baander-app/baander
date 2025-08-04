<?php

namespace App\Modules\Metadata\Search;

use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter as DiscogsReleaseFilter;
use App\Http\Integrations\MusicBrainz\Filters\ReleaseFilter as MusicBrainzReleaseFilter;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Models\Album;
use App\Modules\Metadata\Matching\MatchingStrategy;
use App\Modules\Metadata\Matching\QualityValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AlbumSearchService
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
     * Search all sources for album metadata with fallback strategy
     */
    public function searchAllSources(Album $album): array
    {
        Log::debug('Starting searchAllSources for album', [
            'album_id'      => $album->id,
            'title'         => $album->title,
            'artists_count' => $album->artists->count(),
            'first_artist'  => $album->artists->first()?->name,
        ]);

        $results = [];

        // Try MusicBrainz first (unless it's various artists or circuit breaker is active)
        if (!$this->isVariousArtistsAlbum($album) && $this->canMakeMusicBrainzRequest()) {
            $results['musicbrainz'] = $this->searchMusicBrainz($album);
        } else {
            $results['musicbrainz'] = null;
            Log::debug('Skipping MusicBrainz search', [
                'album_id' => $album->id,
                'reason'   => $this->isVariousArtistsAlbum($album) ? 'various_artists' : 'circuit_breaker',
            ]);
        }

        // Try Discogs (unless it's various artists or circuit breaker is active)
        if (!$this->isVariousArtistsAlbum($album) && $this->canMakeDiscogsRequest()) {
            $results['discogs'] = $this->searchDiscogs($album);
        } else {
            $results['discogs'] = null;
            Log::debug('Skipping Discogs search', [
                'album_id' => $album->id,
                'reason'   => $this->isVariousArtistsAlbum($album) ? 'various_artists' : 'circuit_breaker',
            ]);
        }

        Log::debug('Search results summary', [
            'album_id'          => $album->id,
            'musicbrainz_found' => $results['musicbrainz'] !== null,
            'discogs_found'     => $results['discogs'] !== null,
            'musicbrainz_score' => $results['musicbrainz']['quality_score'] ?? 'N/A',
            'discogs_score'     => $results['discogs']['quality_score'] ?? 'N/A',
        ]);

        return $results;
    }

    /**
     * Check if album is a "Various Artists" compilation
     */
    private function isVariousArtistsAlbum(Album $album): bool
    {
        if ($album->artists->isEmpty()) {
            return false;
        }

        $firstArtist = $album->artists->first()->name;
        $variousArtistsNames = [
            'various artists',
            'various',
            'compilation',
            'soundtrack',
            'va',
            'mixed',
        ];

        return in_array(mb_strtolower($firstArtist), $variousArtistsNames);
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
     * Search MusicBrainz for album
     */
    public function searchMusicBrainz(Album $album): ?array
    {
        // Skip various artists albums early
        if ($this->isVariousArtistsAlbum($album)) {
            Log::debug('Skipping various artists album for MusicBrainz search', [
                'album_id' => $album->id,
                'title'    => $album->title,
            ]);
            return null;
        }

        // Check circuit breaker
        if (!$this->canMakeMusicBrainzRequest()) {
            Log::warning('MusicBrainz requests blocked by circuit breaker', [
                'album_id' => $album->id,
            ]);
            return null;
        }

        try {
            $filter = new MusicBrainzReleaseFilter();
            $filter->setTitle($album->title);

            if ($album->artists->isNotEmpty()) {
                $filter->setArtistName($album->artists->first()->name);
            }

            Log::debug('Searching MusicBrainz for album', [
                'album_id'     => $album->id,
                'title'        => $album->title,
                'artist'       => $album->artists->first()->name ?? null,
                'search_query' => $filter->toArray(),
            ]);

            $searchResults = $this->musicBrainzClient->search->release($filter);

            Log::debug('MusicBrainz raw search results', [
                'album_id'         => $album->id,
                'total_results'    => $searchResults->count(),
                'first_few_titles' => $searchResults->take(3)->pluck('title')->toArray(),
            ]);

            if ($searchResults->isEmpty()) {
                Log::info('No MusicBrainz results found', ['album_id' => $album->id]);
                return null;
            }

            // Limit to top 20 results for performance
            $limitedResults = $searchResults->take(20);

            // Convert search results to array format for matching strategy
            $resultsArray = $limitedResults->map(function ($release) {
                return $this->convertMusicBrainzReleaseToArray($release);
            })->toArray();

            // Use MatchingStrategy to find the best match
            $bestMatch = $this->matchingStrategy->findBestAlbumMatch($resultsArray, $album);

            if (!$bestMatch) {
                Log::info('No suitable MusicBrainz match found after strategy filtering', ['album_id' => $album->id]);
                return null;
            }

            // Get detailed data for the best match with retry logic
            $detailedData = $this->getMusicBrainzDetailedDataWithRetry($bestMatch['id'], $album->id);

            if (!$detailedData) {
                Log::warning('Failed to get detailed MusicBrainz data after retries', [
                    'album_id'   => $album->id,
                    'release_id' => $bestMatch['id'],
                ]);
                return null;
            }

            $qualityScore = $this->qualityValidator->scoreAlbumMatch($detailedData->toArray(), $album);

            Log::debug('MusicBrainz match completed', [
                'album_id'          => $album->id,
                'release_id'        => $bestMatch['id'],
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
            Log::error('MusicBrainz album search failed', [
                'album_id' => $album->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            // Record the failure
            $this->recordMusicBrainzFailure();

            return null;
        }
    }

    /**
     * Convert MusicBrainz release object to array format expected by MatchingStrategy
     */
    private function convertMusicBrainzReleaseToArray($release): array
    {
        $trackCount = 0;

        // Calculate total track count if media information is available
        if (isset($release->media) && is_array($release->media)) {
            foreach ($release->media as $medium) {
                if (isset($medium['track-count'])) {
                    $trackCount += (int)$medium['track-count'];
                } else if (isset($medium['tracks']) && is_array($medium['tracks'])) {
                    $trackCount += count($medium['tracks']);
                }
            }
        }

        return [
            'id'                  => $release->id,
            'title'               => $release->title,
            'date'                => $release->date ?? null,
            'artist-credit'       => $release->artist_credit ?? [],
            'score'               => $release->score ?? 0,
            'status'              => $release->status ?? null,
            'packaging'           => $release->packaging ?? null,
            'text-representation' => $release->text_representation ?? null,
            // Add format information for filtering
            'primary-type'        => $release->release_group->primary_type ?? null,
            'secondary-types'     => $release->release_group->secondary_types ?? [],
            // Add track count for better matching
            'track_count'         => $trackCount > 0 ? $trackCount : null,
            'media'               => $release->media ?? [],
        ];
    }

    /**
     * Get MusicBrainz detailed data with retry logic
     */
    private function getMusicBrainzDetailedDataWithRetry(string $releaseId, int $albumId, int $maxRetries = 3): mixed
    {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            try {
                Log::debug('Attempting MusicBrainz lookup', [
                    'album_id'   => $albumId,
                    'release_id' => $releaseId,
                    'attempt'    => $attempt,
                ]);

                // Add timeout and better error handling
                $startTime = microtime(true);
                $detailedData = $this->musicBrainzClient->lookup->release($releaseId);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                if ($detailedData) {
                    Log::debug('MusicBrainz lookup successful', [
                        'album_id'    => $albumId,
                        'release_id'  => $releaseId,
                        'attempt'     => $attempt,
                        'duration_ms' => $duration,
                    ]);
                    return $detailedData;
                } else {
                    Log::warning('MusicBrainz lookup returned null', [
                        'album_id'    => $albumId,
                        'release_id'  => $releaseId,
                        'attempt'     => $attempt,
                        'duration_ms' => $duration,
                    ]);
                }

            } catch (\Exception $e) {
                Log::warning('MusicBrainz lookup attempt failed', [
                    'album_id'    => $albumId,
                    'release_id'  => $releaseId,
                    'attempt'     => $attempt,
                    'error'       => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);

                // Check if it's a rate limit error
                if (str_contains(mb_strtolower($e->getMessage()), 'rate limit') ||
                    str_contains(mb_strtolower($e->getMessage()), '429')) {
                    Log::warning('MusicBrainz rate limit detected', [
                        'album_id'   => $albumId,
                        'release_id' => $releaseId,
                    ]);

                    // Cache rate limit for longer period
                    Cache::put('musicbrainz_rate_limit', true, now()->addMinutes(5));
                    break;
                }

                // Check if it's a network timeout
                if (str_contains(mb_strtolower($e->getMessage()), 'timeout') ||
                    str_contains(mb_strtolower($e->getMessage()), 'curl')) {
                    Log::warning('MusicBrainz network issue detected', [
                        'album_id'   => $albumId,
                        'release_id' => $releaseId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            if ($attempt < $maxRetries) {
                $waitTime = min($attempt * 2, 10); // Cap at 10 seconds
                Log::debug('Waiting before MusicBrainz retry', [
                    'album_id'     => $albumId,
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
     * Search Discogs for album with improved error handling
     */
    public function searchDiscogs(Album $album): ?array
    {
        if ($this->isVariousArtistsAlbum($album)) {
            Log::debug('Skipping various artists album for external search', [
                'album_id' => $album->id,
                'title'    => $album->title,
            ]);
            return null;
        }

        if (!$this->canMakeDiscogsRequest()) {
            Log::warning('Discogs rate limit reached, skipping search', [
                'album_id' => $album->id,
            ]);
            return null;
        }

        try {
            $filter = new DiscogsReleaseFilter();
            $filter->setTitle($album->title);

            if ($album->artists->isNotEmpty()) {
                $filter->setArtist($album->artists->first()->name);
            }

            if ($album->year) {
                $filter->setYear($album->year);
            }

            Log::debug('Searching Discogs for album', [
                'album_id'     => $album->id,
                'title'        => $album->title,
                'artist'       => $album->artists->first()->name ?? null,
                'year'         => $album->year,
                'search_query' => $filter->toArray(),
            ]);

            $searchResults = $this->discogsClient->search->release($filter);

            Log::debug('Discogs raw search results', [
                'album_id'         => $album->id,
                'total_results'    => $searchResults->count(),
                'first_few_titles' => $searchResults->take(3)->pluck('title')->toArray(),
            ]);

            if ($searchResults->isEmpty()) {
                Log::info('No Discogs results found', ['album_id' => $album->id]);
                return null;
            }

            // Limit to top 15 results for performance (Discogs typically has fewer duplicates)
            $limitedResults = $searchResults->take(15);

            // Convert search results to array format for matching strategy
            $resultsArray = $limitedResults->map(function ($release) {
                return $this->convertDiscogsReleaseToArray($release);
            })->toArray();

            // Use MatchingStrategy to find the best match
            $bestMatch = $this->matchingStrategy->findBestAlbumMatch($resultsArray, $album);

            if (!$bestMatch) {
                Log::info('No suitable Discogs match found after strategy filtering', ['album_id' => $album->id]);
                return null;
            }

            // Get detailed data for the best match with retry logic
            $detailedData = $this->getDiscogsDetailedDataWithRetry($bestMatch['id'], $album->id);

            if (!$detailedData) {
                Log::warning('Failed to get detailed Discogs data after retries', [
                    'album_id'   => $album->id,
                    'release_id' => $bestMatch['id'],
                ]);
                return null;
            }

            $qualityScore = $this->qualityValidator->scoreAlbumMatch($detailedData->toArray(), $album);

            Log::debug('Discogs match completed', [
                'album_id'          => $album->id,
                'release_id'        => $bestMatch['id'],
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
            Log::error('Discogs album search failed', [
                'album_id' => $album->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Convert Discogs release object to array format expected by MatchingStrategy
     */
    private function convertDiscogsReleaseToArray($release): array
    {
        return [
            'id'      => $release->id,
            'title'   => $release->title,
            'year'    => $release->year ?? null,
            'artists' => $release->artists ?? [],
            'formats' => $release->formats ?? [],
            'labels'  => $release->labels ?? [],
            'genres'  => $release->genres ?? [],
            'styles'  => $release->styles ?? [],
        ];
    }

    /**
     * Get Discogs detailed data with retry logic
     */
    private function getDiscogsDetailedDataWithRetry(string $releaseId, int $albumId, int $maxRetries = 2): mixed
    {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            try {
                Log::debug('Attempting Discogs lookup', [
                    'album_id'   => $albumId,
                    'release_id' => $releaseId,
                    'attempt'    => $attempt,
                ]);

                $detailedData = $this->discogsClient->lookup->release($releaseId);

                if ($detailedData) {
                    Log::debug('Discogs lookup successful', [
                        'album_id'   => $albumId,
                        'release_id' => $releaseId,
                        'attempt'    => $attempt,
                    ]);
                    return $detailedData;
                }

            } catch (\Exception $e) {
                Log::warning('Discogs lookup attempt failed', [
                    'album_id'   => $albumId,
                    'release_id' => $releaseId,
                    'attempt'    => $attempt,
                    'error'      => $e->getMessage(),
                ]);

                // Don't retry on 500 errors immediately - they're usually server-side issues
                if (str_contains($e->getMessage(), '500') || str_contains($e->getMessage(), 'Internal Server Error')) {
                    Log::info('Skipping retry for Discogs server error', [
                        'album_id'   => $albumId,
                        'release_id' => $releaseId,
                    ]);
                    break;
                }
            }

            if ($attempt < $maxRetries) {
                $waitTime = $attempt * 3; // Progressive backoff: 3s, 6s
                Log::debug('Waiting before Discogs retry', [
                    'album_id'     => $albumId,
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
     * Search for album with fuzzy matching - returns structured results
     */
    public function searchFuzzy(Album $album): array
    {
        $allResults = [];
        $variations = $this->generateAlbumTitleVariations($album->title);

        foreach ($variations as $variation) {
            // Try MusicBrainz
            if ($this->canMakeMusicBrainzRequest()) {
                $filter = new MusicBrainzReleaseFilter();
                $filter->setTitle($variation);

                if ($album->artists->isNotEmpty()) {
                    $filter->setArtistName($album->artists->first()->name);
                }

                try {
                    $searchResults = $this->musicBrainzClient->search->release($filter);
                    if (!$searchResults->isEmpty()) {
                        foreach ($searchResults->take(3) as $result) {
                            $allResults[] = [
                                'id' => $result->id,
                                'source' => 'musicbrainz',
                                'variation_used' => $variation,
                                'data' => $this->convertMusicBrainzReleaseToArray($result),
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
                $filter = new DiscogsReleaseFilter();
                $filter->setTitle($variation);

                if ($album->artists->isNotEmpty()) {
                    $filter->setArtist($album->artists->first()->name);
                }

                try {
                    $searchResults = $this->discogsClient->search->release($filter);
                    if (!$searchResults->isEmpty()) {
                        foreach ($searchResults->take(3) as $result) {
                            $allResults[] = [
                                'id' => $result->id,
                                'source' => 'discogs',
                                'variation_used' => $variation,
                                'data' => $this->convertDiscogsReleaseToArray($result),
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
        $scoredResults = array_map(function ($result) use ($album) {
            $qualityScore = $this->qualityValidator->scoreAlbumMatch($result['data'], $album);
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
     * Generate album title variations for fuzzy matching
     */
    private function generateAlbumTitleVariations(string $title): array
    {
        $variations = [$title];

        // Remove common prefixes/suffixes
        $cleanTitle = preg_replace('/^(the\s+|a\s+|an\s+)/i', '', $title);
        if ($cleanTitle !== $title) {
            $variations[] = $cleanTitle;
        }

        // Add "The" prefix if not present
        if (!preg_match('/^the\s+/i', $title)) {
            $variations[] = 'The ' . $title;
        }

        // Replace special characters
        $normalized = preg_replace('/[^\w\s]/', '', $title);
        if ($normalized !== $title) {
            $variations[] = $normalized;
        }

        // Remove edition info like "(Deluxe Edition)", "(Remastered)", etc.
        $withoutEdition = preg_replace('/\s*\([^)]*(?:edition|remaster|remix|version|bonus)[^)]*\)/i', '', $title);
        if ($withoutEdition !== $title) {
            $variations[] = trim($withoutEdition);
        }

        // Remove year from title if present
        $withoutYear = preg_replace('/\s*\(\d{4}\)/', '', $title);
        if ($withoutYear !== $title) {
            $variations[] = trim($withoutYear);
        }

        // Remove duplicates and return
        return array_unique($variations);
    }

    /**
     * Debug MusicBrainz client health
     */
    private function debugMusicBrainzHealth(): array
    {
        try {
            $startTime = microtime(true);

            // Try a simple search to test connectivity
            $filter = new MusicBrainzReleaseFilter();
            $filter->setTitle('test');
            $filter->setLimit(1);

            $testResults = $this->musicBrainzClient->search->release($filter);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status'             => 'healthy',
                'response_time_ms'   => $duration,
                'test_results_count' => $testResults->count(),
            ];

        } catch (\Exception $e) {
            return [
                'status'      => 'unhealthy',
                'error'       => $e->getMessage(),
                'error_class' => get_class($e),
            ];
        }
    }

}