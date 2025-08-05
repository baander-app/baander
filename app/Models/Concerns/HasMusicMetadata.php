<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

trait HasMusicMetadata
{
    /**
     * Get content-based recommendations using user listening patterns
     */
    public function getRecommendationsByListeningHistory(int $userId, int $limit = 20): Collection
    {
        // Get user's most played genres and artists
        $userPreferences = DB::select('
            SELECT 
                g.id as genre_id,
                g.name as genre_name,
                a.id as artist_id,
                a.name as artist_name,
                COUNT(*) as play_count
            FROM user_media_activities uma
            JOIN songs s ON uma.user_media_activityable_id = s.id
            JOIN genre_song gs ON s.id = gs.song_id
            JOIN genres g ON gs.genre_id = g.id
            JOIN artist_song ars ON s.id = ars.song_id
            JOIN artists a ON ars.artist_id = a.id
            WHERE uma.user_id = ? 
                AND uma.user_media_activityable_type = ?
                AND uma.created_at >= NOW() - INTERVAL \'30 days\'
            GROUP BY g.id, g.name, a.id, a.name
            ORDER BY play_count DESC
            LIMIT 10
        ', [$userId, static::class]);

        $preferredGenreIds = collect($userPreferences)->pluck('genre_id')->unique()->toArray();
        $preferredArtistIds = collect($userPreferences)->pluck('artist_id')->unique()->toArray();

        return $this->getSimilarByMetadata([
            'genres'  => $preferredGenreIds,
            'artists' => $preferredArtistIds,
        ], $limit);
    }

    /**
     * Get songs with similar musical characteristics
     */
    public function getSimilarByMetadata(array $criteria = [], int $limit = 20): Collection
    {
        $baseQuery = static::query()->where('id', '!=', $this->id);

        // Genre-based similarity (strongest indicator)
        if (!empty($criteria['genres']) || $this->genres->isNotEmpty()) {
            $genreNames = $criteria['genres'] ?? $this->genres->pluck('name')->toArray();
            $baseQuery->whereHas('genres', function ($query) use ($genreNames) {
                $query->whereIn('name', $genreNames);
            });
        }

        // Year proximity (+-3 years)
        if (isset($criteria['year']) || $this->year) {
            $targetYear = $criteria['year'] ?? $this->year;
            if ($targetYear) {
                $baseQuery->whereBetween('year', [$targetYear - 3, $targetYear + 3]);
            }
        }

        // Duration similarity (+-30 seconds)
        if (isset($criteria['duration']) || $this->length) {
            $targetDuration = $criteria['duration'] ?? $this->length;
            if ($targetDuration) {
                $baseQuery->whereBetween('length', [$targetDuration - 30, $targetDuration + 30]);
            }
        }

        // Artist connection (same artists or collaborators)
        if (!empty($criteria['artists']) || $this->artists->isNotEmpty()) {
            $artistIds = $criteria['artists'] ?? $this->artists->pluck('id')->toArray();
            $baseQuery->whereHas('artists', function ($query) use ($artistIds) {
                $query->whereIn('artists.id', $artistIds);
            });
        }

        return $baseQuery->with(['artists', 'album', 'genres'])
            ->limit($limit)
            ->get();
    }
}