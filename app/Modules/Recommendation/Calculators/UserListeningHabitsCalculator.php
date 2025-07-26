<?php

namespace App\Modules\Recommendation\Calculators;

use App\Modules\Recommendation\Contracts\CalculatorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserListeningHabitsCalculator implements CalculatorInterface
{
    /**
     * Calculate recommendations based on user listening habits
     *
     * @param mixed $sourceData Source model(s) to calculate recommendations for
     * @param array $configuration Configuration parameters for calculation
     * @return array Array of recommendations [sourceId => [targetId => score]]
     * @throws InvalidArgumentException
     */
    public function calculate($sourceData, array $configuration): array
    {
        // Validate required configuration parameters
        $this->validateConfiguration($configuration);

        // Extract configuration parameters
        $userActivityTable = $configuration['user_activity_table'] ?? 'user_media_activities';
        $userField = $configuration['user_field'] ?? 'user_id';
        $mediaField = $configuration['media_field'] ?? 'userMediaActivityable_id';
        $mediaTypeField = $configuration['media_type_field'] ?? 'userMediaActivityable_type';
        $mediaType = $configuration['media_type'] ?? 'App\\Models\\Song';
        $playCountField = $configuration['play_count_field'] ?? 'play_count';
        $loveField = $configuration['love_field'] ?? 'love';
        $maxRecommendations = $configuration['count'] ?? config('recommendation.defaults.count', 10);
        $maxArtistSongsPerPeriod = $configuration['max_artist_songs_per_period'] ?? 5;
        $artistRecommendationPeriodDays = $configuration['artist_recommendation_period_days'] ?? 3;

        // Query the database to get user listening data
        $query = DB::table($userActivityTable)
            ->select(
                DB::raw("{$userField} as user_id"),
                DB::raw("{$mediaField} as media_id"),
                DB::raw("{$playCountField} as play_count"),
                DB::raw("{$loveField} as love"),
            )
            ->where($mediaTypeField, $mediaType);

        // Apply data filters if specified
        if (isset($configuration['data_table_filter']) && is_array($configuration['data_table_filter'])) {
            foreach ($configuration['data_table_filter'] as $field => $filterConfig) {
                $this->applyFilter($query, $field, $filterConfig);
            }
        }

        $listeningData = $query->get();

        // Group the data by user and media
        $userListeningHistory = []; // user_id => [media_id => [play_count, love]]
        $mediaUsers = []; // media_id => [user_id => [play_count, love]]

        foreach ($listeningData as $row) {
            // Map users to their listening history
            if (!isset($userListeningHistory[$row->user_id])) {
                $userListeningHistory[$row->user_id] = [];
            }
            $userListeningHistory[$row->user_id][$row->media_id] = [
                'play_count' => $row->play_count,
                'love'       => $row->love,
            ];

            // Map media to users who listened to them
            if (!isset($mediaUsers[$row->media_id])) {
                $mediaUsers[$row->media_id] = [];
            }
            $mediaUsers[$row->media_id][$row->user_id] = [
                'play_count' => $row->play_count,
                'love'       => $row->love,
            ];
        }

        // Calculate recommendations based on user similarity and listening patterns
        $recommendations = [];

        foreach ($mediaUsers as $sourceId => $sourceUsers) {
            $mediaRecommendations = [];

            // Skip items with no user data
            if (empty($sourceUsers)) {
                continue;
            }

            foreach ($mediaUsers as $targetId => $targetUsers) {
                // For self-recommendations, explore music in the same style instead of skipping (and lonely)
                if (count($mediaUsers) === 1 && $sourceId === $targetId) {
                    // Find songs with similar style based on genres
                    $similarStyleSongs = $this->findSimilarStyleSongs($sourceId, $mediaUsers, $maxRecommendations);

                    // Add these to our recommendations with appropriate scores
                    foreach ($similarStyleSongs as $similarSongId => $similarityScore) {
                        // Apply user-specific artist recommendation limits
                        $songArtists = $this->getSongArtists($similarSongId);
                        $artistLimitReached = false;

                        // Check if any of the song's artists have reached the recommendation limit for any user
                        foreach ($songArtists as $artistId) {
                            foreach (array_keys($sourceUsers) as $userId) {
                                if ($this->hasReachedArtistRecommendationLimit(
                                    $userId,
                                    $artistId,
                                    $maxArtistSongsPerPeriod,
                                    $artistRecommendationPeriodDays,
                                )) {
                                    $artistLimitReached = true;
                                    break;
                                }
                            }
                            if ($artistLimitReached) {
                                break;
                            }
                        }

                        // Only add the recommendation if the artist limit hasn't been reached
                        if (!$artistLimitReached) {
                            $mediaRecommendations[$similarSongId] = $similarityScore;
                        }
                    }
                }

                // Calculate similarity score based on common users and their listening patterns
                $similarityScore = $this->calculateMediaSimilarity(
                    $sourceUsers,
                    $targetUsers,
                    $userListeningHistory,
                );

                if ($similarityScore > 0) {
                    $score = $similarityScore * 100; // Convert to 0-100 scale

                    // Find common users between source and target
                    $commonUsers = array_intersect(array_keys($sourceUsers), array_keys($targetUsers));

                    // Check if songs share the same artist and if it's the same user
                    if (!empty($commonUsers) && $this->hasSameArtist($sourceId, $targetId)) {
                        // Boost score significantly for same artist when it's the same user
                        $score *= 1.5; // 50% boost for same artist recommendations

                        // Apply user-specific artist recommendation limits
                        $songArtists = $this->getSongArtists($targetId);
                        $artistLimitReached = false;

                        // Check if any of the song's artists have reached the recommendation limit for any common user
                        foreach ($songArtists as $artistId) {
                            foreach ($commonUsers as $userId) {
                                if ($this->hasReachedArtistRecommendationLimit(
                                    $userId,
                                    $artistId,
                                    $maxArtistSongsPerPeriod,
                                    $artistRecommendationPeriodDays,
                                )) {
                                    $artistLimitReached = true;
                                    break;
                                }
                            }
                            if ($artistLimitReached) {
                                break;
                            }
                        }

                        // Skip this recommendation if the artist limit has been reached
                        if ($artistLimitReached) {
                            continue;
                        }
                    }

                    $mediaRecommendations[$targetId] = $score;
                }
            }

            // Sort recommendations by score and limit-to-max recommendations
            if (!empty($mediaRecommendations)) {
                arsort($mediaRecommendations);
                $recommendations[$sourceId] = array_slice($mediaRecommendations, 0, $maxRecommendations, true);
            }
        }

        return $recommendations;
    }

    /**
     * Calculate similarity between two media items based on user listening patterns
     *
     * @param array $sourceUsers Users who listened to source media
     * @param array $targetUsers Users who listened to target media
     * @param array $userListeningHistory Complete user listening history
     * @return float Similarity score between 0-1
     */
    private function calculateMediaSimilarity(
        array $sourceUsers,
        array $targetUsers,
        array $userListeningHistory,
    ): float
    {
        // Find common users
        $commonUsers = array_intersect(array_keys($sourceUsers), array_keys($targetUsers));

        if (empty($commonUsers)) {
            return 0.0;
        }

        $similarityScore = 0.0;
        $totalWeight = 0.0;

        foreach ($commonUsers as $userId) {
            $sourcePlayCount = $sourceUsers[$userId]['play_count'];
            $targetPlayCount = $targetUsers[$userId]['play_count'];
            $sourceLove = $sourceUsers[$userId]['love'] ? 1 : 0;
            $targetLove = $targetUsers[$userId]['love'] ? 1 : 0;

            // Calculate user weight based on their overall listening activity
            $userWeight = 1.0;
            if (isset($userListeningHistory[$userId])) {
                $totalUserPlays = array_sum(array_column(array_map(function ($item) {
                    return ['play_count' => $item['play_count']];
                }, $userListeningHistory[$userId]), 'play_count'));

                // Users with more diverse listening habits have higher weight
                $userWeight = min(1.0, count($userListeningHistory[$userId]) / 20); // Cap at 20 items

                // But extremely high play counts might indicate bias, so normalize
                if ($totalUserPlays > 0) {
                    $userWeight *= min(1.0, 100 / $totalUserPlays); // Normalize for users with >100 plays
                }
            }

            // Calculate item similarity for this user
            $playCountSimilarity = 1.0 - (abs($sourcePlayCount - $targetPlayCount) / max(max($sourcePlayCount, $targetPlayCount), 1));
            $loveSimilarity = ($sourceLove == $targetLove) ? 1.0 : 0.0;

            // Combine play count and love with appropriate weights
            $itemSimilarity = ($playCountSimilarity * 0.7) + ($loveSimilarity * 0.3);

            // Add to the total similarity with user weight
            $similarityScore += $itemSimilarity * $userWeight;
            $totalWeight += $userWeight;
        }

        // Normalize by total weight
        return ($totalWeight > 0) ? ($similarityScore / $totalWeight) : 0.0;
    }

    /**
     * Check if two songs share the same artist
     *
     * @param int|string $songId1
     * @param int|string $songId2
     * @return bool
     */
    private function hasSameArtist(int|string $songId1, int|string $songId2): bool
    {
        // Get artists for song 1
        $cacheKey1 = "recommendations:user_listening_habits:song_artists:{$songId1}";
        $artists1 = Cache::remember($cacheKey1, now()->addHours(24), function () use ($songId1) {
            return DB::table('artist_song')
                ->where('song_id', $songId1)
                ->pluck('artist_id')
                ->toArray();
        });

        // Get artists for song 2
        $cacheKey2 = "recommendations:user_listening_habits:song_artists:{$songId2}";
        $artists2 = Cache::remember($cacheKey2, now()->addHours(24), function () use ($songId2) {
            return DB::table('artist_song')
                ->where('song_id', $songId2)
                ->pluck('artist_id')
                ->toArray();
        });

        // Check if they share any artists
        return !empty(array_intersect($artists1, $artists2));
    }

    /**
     * Find songs with similar style based on genres
     *
     * @param int|string $songId Source song ID
     * @param array $mediaUsers All media users data
     * @param int $maxRecommendations Maximum number of recommendations to return
     * @return array Array of similar songs with scores [songId => score]
     */
    private function findSimilarStyleSongs(int|string $songId, array $mediaUsers, int $maxRecommendations): array
    {
        // Get genres for the source song
        $songGenresCacheKey = "recommendations:user_listening_habits:song_genres:{$songId}";
        $sourceGenres = Cache::remember($songGenresCacheKey, now()->addHours(24), function () use ($songId) {
            return DB::table('genre_song')
                ->where('song_id', $songId)
                ->pluck('genre_id')
                ->toArray();
        });

        if (empty($sourceGenres)) {
            return [];
        }

        $similarSongs = [];

        // Find songs in the same genres
        foreach ($sourceGenres as $genreId) {
            // Get songs for this genre
            $genreSongsCacheKey = "recommendations:user_listening_habits:genre_songs:{$genreId}:{$songId}";
            $genreSongs = Cache::remember($genreSongsCacheKey, now()->addHours(12), function () use ($genreId, $songId) {
                return DB::table('genre_song')
                    ->where('genre_id', $genreId)
                    ->where('song_id', '!=', $songId) // Exclude the source song
                    ->pluck('song_id')
                    ->toArray();
            });

            // Calculate a base score for each song in this genre
            foreach ($genreSongs as $genreSongId) {
                // Skip if we've already processed this song or it's not in our media users data
                if (!isset($mediaUsers[$genreSongId]) || isset($similarSongs[$genreSongId])) {
                    continue;
                }

                // Get all genres for this song
                $targetGenresCacheKey = "recommendations:user_listening_habits:song_genres:{$genreSongId}";
                $targetGenres = Cache::remember($targetGenresCacheKey, now()->addHours(24), function () use ($genreSongId) {
                    return DB::table('genre_song')
                        ->where('song_id', $genreSongId)
                        ->pluck('genre_id')
                        ->toArray();
                });

                // Calculate genre overlap score (Jaccard similarity)
                $commonGenres = count(array_intersect($sourceGenres, $targetGenres));
                $allGenres = count(array_unique(array_merge($sourceGenres, $targetGenres)));
                $genreSimilarity = $allGenres > 0 ? $commonGenres / $allGenres : 0;

                // Boost the score if it's by the same artist
                $artistBoost = $this->hasSameArtist($songId, $genreSongId) ? 1.5 : 1.0;

                // Calculate final score (0-100 scale)
                $similarSongs[$genreSongId] = $genreSimilarity * $artistBoost * 100;
            }
        }

        // Sort by score and limit
        arsort($similarSongs);
        return array_slice($similarSongs, 0, $maxRecommendations, true);
    }

    /**
     * Validate that required configuration parameters are present
     *
     * @param array $configuration
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateConfiguration(array $configuration): void
    {
        $requiredParams = ['count'];

        foreach ($requiredParams as $param) {
            if (!isset($configuration[$param])) {
                throw new InvalidArgumentException("Missing required configuration parameter: {$param}");
            }
        }
    }

    /**
     * Apply a filter to the database query
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $field
     * @param mixed $filterConfig
     * @return void
     */
    private function applyFilter($query, string $field, mixed $filterConfig): void
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

    /**
     * Check if the artist has reached the maximum number of recommendations for a user in the specified period
     *
     * @param int|string $userId User ID
     * @param int|string $artistId Artist ID
     * @param int $maxSongs Maximum number of songs allowed from the same artist
     * @param int $periodDays Period in days to check
     * @return bool True if the artist has reached the maximum, false otherwise
     */
    private function hasReachedArtistRecommendationLimit(
        int|string $userId,
        int|string $artistId,
        int        $maxSongs = 5,
        int        $periodDays = 3,
    ): bool
    {
        // Create a cache key for this user-artist combination
        $cacheKey = "recommendations:user_listening_habits:artist_recommendation_limit:{$userId}:{$artistId}:{$periodDays}";

        // Cache the count for a short period (1 hour) as this data can change frequently
        $recentRecommendationsCount = Cache::remember($cacheKey, now()->addHour(), function () use ($userId, $artistId, $periodDays) {
            // Get recommendations for this user-artist combination in the specified period
            return DB::table('recommendations')
                ->join('artist_song', 'recommendations.target_id', '=', 'artist_song.song_id')
                ->where('artist_song.artist_id', $artistId)
                ->where('recommendations.created_at', '>=', now()->subDays($periodDays))
                ->where('recommendations.source_id', $userId)
                ->count();
        });

        return $recentRecommendationsCount >= $maxSongs;
    }

    /**
     * Get artist IDs for a song
     *
     * @param int|string $songId Song ID
     * @return array Array of artist IDs
     */
    private function getSongArtists(int|string $songId): array
    {
        $cacheKey = "recommendations:user_listening_habits:song_artists:{$songId}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($songId) {
            return DB::table('artist_song')
                ->where('song_id', $songId)
                ->pluck('artist_id')
                ->toArray();
        });
    }
}
