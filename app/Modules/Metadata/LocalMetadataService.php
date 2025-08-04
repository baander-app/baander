<?php

namespace App\Modules\Metadata;

use App\Models\Album;
use Exception;
use Illuminate\Support\Facades\Log;

class LocalMetadataService
{
    /**
     * Enhance album metadata using local data analysis
     */
    public function enhanceAlbumMetadata(Album $album): array
    {
        $results = [
            'album'         => null,
            'artists'       => [],
            'songs'         => [],
            'genres'        => [],
            'quality_score' => 0.3, // Lower than external sources but better than nothing
            'source'        => 'local_analysis',
        ];

        try {
            // Analyze album based on existing data
            $albumData = $this->analyzeAlbumData($album);
            $artistsData = $this->analyzeArtists($album);
            $songsData = $this->analyzeSongs($album);

            $results = [
                'album'         => $albumData,
                'artists'       => $artistsData,
                'songs'         => $songsData,
                'quality_score' => $this->calculateLocalQualityScore($album),
                'source'        => 'local_analysis',
            ];

            Log::info('Local metadata enhancement completed', [
                'album_id'      => $album->id,
                'quality_score' => $results['quality_score'],
            ]);

        } catch (Exception $e) {
            Log::error('Local metadata enhancement failed', [
                'album_id' => $album->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $results;
    }

    private function analyzeAlbumData(Album $album): array
    {
        return [
            'title'           => $album->title,
            'year'            => $album->year,
            'track_count'     => $album->songs()->count(),
            'total_duration'  => $album->songs()->sum('length'),
            'external_source' => 'local_analysis',
        ];
    }

    private function analyzeArtists(Album $album): array
    {
        return $album->artists->map(function ($artist) {
            return [
                'name'            => $artist->name,
                'album_count'     => $artist->albums()->count(),
                'song_count'      => $artist->songs()->count(),
                'external_source' => 'local_analysis',
            ];
        })->toArray();
    }

    private function analyzeSongs(Album $album): array
    {
        return $album->songs->map(function ($song) {
            return [
                'title'           => $song->title,
                'track'           => $song->track,
                'length'          => $song->length,
                'file_format'     => pathinfo($song->path, PATHINFO_EXTENSION),
                'external_source' => 'local_analysis',
            ];
        })->toArray();
    }

    private function calculateLocalQualityScore(Album $album): float
    {
        $score = 0.1; // Base score

        // Add points for available data
        if ($album->year) $score += 0.1;
        if ($album->artists->isNotEmpty()) $score += 0.1;
        if ($album->songs->isNotEmpty()) $score += 0.1;

        // Add points for data richness
        $songCount = $album->songs()->count();
        if ($songCount > 0) $score += min(0.2, $songCount * 0.02);

        // Add points for metadata completeness
        $songsWithLength = $album->songs()->whereNotNull('length')->count();
        if ($songCount > 0 && $songsWithLength > 0) {
            $score += ($songsWithLength / $songCount) * 0.2;
        }

        return min($score, 0.6); // Cap at 0.6 for local analysis
    }

    private function detectThaiGenres(Album $album): array
    {
        $genres = [];
        $title = strtolower($album->title);
        $artistName = strtolower($album->artists->first()->name ?? '');

        // Simple heuristics for Thai music genres
        if (preg_match('/\p{Thai}/u', $album->title) || preg_match('/\p{Thai}/u', $artistName)) {
            // If contains Thai characters, likely Thai music
            $genres[] = 'Thai Pop';

            // Additional heuristics based on common Thai words or patterns
            if (str_contains($title, 'ลูกทุ่ง')) {
                $genres[] = 'Luk Thung';
            } else if (str_contains($title, 'มอลำ')) {
                $genres[] = 'Mor Lam';
            } else if (str_contains($title, 'สตริง')) {
                $genres[] = 'String';
            }
        }

        // Analyze numeric artist names (common in Thai indie scene)
        if (preg_match('/^\d+$/', $artistName)) {
            $genres[] = 'Indie';
            $genres[] = 'Alternative';
        }

        return $genres;
    }
}