<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

trait HasContentSimilarity
{
    /**
     * Advanced content similarity using PostgreSQL functions
     */
    public static function findContentSimilar(
        array $genreIds = [],
        array $artistIds = [],
        ?int  $duration = null,
        ?int  $year = null,
        int   $limit = 50,
    ): Collection
    {
        $results = DB::select('SELECT * FROM search_songs_by_content(?, ?, ?, ?, ?)', [
            $genreIds,
            $artistIds,
            $duration,
            $year,
            $limit,
        ]);

        return static::hydrate($results);
    }

    /**
     * Find songs with similar musical content (genre, artists, tempo, etc.)
     */
    public function findSimilarByContent(int $limit = 10): Collection
    {
        return $this->findSimilarByGenres($limit)
            ->merge($this->findSimilarByArtists($limit))
            ->merge($this->findSimilarByDuration($limit))
            ->unique('id')
            ->take($limit);
    }

    /**
     * Find songs with similar genres using PostgreSQL function
     */
    public function findSimilarByGenres(int $limit = 20): Collection
    {
        $results = DB::select('SELECT * FROM find_songs_by_genre_cluster(?, ?)', [
            $this->id,
            $limit,
        ]);

        $songIds = collect($results)->pluck('similar_song_id')->toArray();

        return static::whereIn('id', $songIds)
            ->with(['artists', 'album', 'genres'])
            ->get();
    }

    /**
     * Find songs by similar artists or collaborating artists
     */
    public function findSimilarByArtists(int $limit = 20): Collection
    {
        $artistIds = $this->artists->pluck('id')->toArray();

        if (empty($artistIds)) {
            return collect();
        }

        return static::whereHas('artists', function ($query) use ($artistIds) {
            $query->whereIn('artists.id', $artistIds);
        })
            ->where('id', '!=', $this->id)
            ->withCount(['artists' => function ($query) use ($artistIds) {
                $query->whereIn('artists.id', $artistIds);
            }])
            ->orderByDesc('artists_count')
            ->with(['artists', 'album', 'genres'])
            ->limit($limit)
            ->get();
    }

    /**
     * Find songs with similar duration (±20%)
     */
    public function findSimilarByDuration(int $limit = 20): Collection
    {
        if (!$this->length) {
            return collect();
        }

        $tolerance = $this->length * 0.2; // 20% tolerance
        $minLength = $this->length - $tolerance;
        $maxLength = $this->length + $tolerance;

        return static::whereBetween('length', [$minLength, $maxLength])
            ->where('id', '!=', $this->id)
            ->with(['artists', 'album', 'genres'])
            ->limit($limit)
            ->get();
    }
}