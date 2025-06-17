<?php

namespace App\Services\Metadata\Search;

use App\Models\Album;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\MusicBrainz\Filters\ReleaseFilter as MusicBrainzReleaseFilter;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter as DiscogsReleaseFilter;
use App\Services\MetadataMatching\{MatchingStrategy, QualityValidator};
use Illuminate\Support\Facades\Log;

class AlbumSearchService
{
    public function __construct(
        private readonly MusicBrainzClient $musicBrainzClient,
        private readonly DiscogsClient $discogsClient,
        private readonly MatchingStrategy $matchingStrategy,
        private readonly QualityValidator $qualityValidator
    ) {}

    /**
     * Search all sources for album metadata
     */
    public function searchAllSources(Album $album): array
    {
        return [
            'musicbrainz' => $this->searchMusicBrainz($album),
            'discogs' => $this->searchDiscogs($album),
        ];
    }

    /**
     * Search MusicBrainz for album
     */
    public function searchMusicBrainz(Album $album): ?array
    {
        try {
            $filter = new MusicBrainzReleaseFilter();
            $filter->setTitle($album->title);

            if ($album->artists->isNotEmpty()) {
                $filter->setArtistName($album->artists->first()->name);
            }


            Log::debug('Searching MusicBrainz for album', [
                'album_id' => $album->id,
                'title' => $album->title,
                'artist' => $album->artists->first()->name ?? null
            ]);

            $searchResults = $this->musicBrainzClient->search->release($filter);

            if ($searchResults->isEmpty()) {
                return null;
            }

            // Smart filtering using Collection methods
            $relevantResults = $searchResults
                ->filter(fn($release) => $this->isRelevantMatch($release, $album))
                ->sortByDesc(fn($release) => $this->calculateRelevanceScore($release, $album))
                ->take(5);

            if ($relevantResults->isEmpty()) {
                return null;
            }

            $bestMatch = $relevantResults->first();
            $detailedData = $this->musicBrainzClient->lookup->release($bestMatch->id);

            return [
                'source' => 'musicbrainz',
                'data' => $detailedData->toArray(),
                'quality_score' => $this->qualityValidator->scoreAlbumMatch($detailedData->toArray(), $album),
                'search_results_count' => $searchResults->count(),
                'filtered_results_count' => $relevantResults->count()
            ];

        } catch (\Exception $e) {
            Log::warning('MusicBrainz album search failed', [
                'album_id' => $album->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Search Discogs for album
     */
    public function searchDiscogs(Album $album): ?array
    {
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
                'album_id' => $album->id,
                'title' => $album->title,
                'artist' => $album->artists->first()->name ?? null
            ]);

            $searchResults = $this->discogsClient->search->release($filter);

            if ($searchResults->isEmpty()) {
                return null;
            }

            $relevantResults = $searchResults
                ->filter(fn($release) => $this->isRelevantDiscogsMatch($release, $album))
                ->sortByDesc(fn($release) => $this->calculateDiscogsRelevanceScore($release, $album))
                ->take(5);

            if ($relevantResults->isEmpty()) {
                return null;
            }

            $bestMatch = $relevantResults->first();
            $detailedData = $this->discogsClient->lookup->release($bestMatch->id);

            if (!$detailedData) {
                return null;
            }

            return [
                'source' => 'discogs',
                'data' => $detailedData->toArray(),
                'quality_score' => $this->qualityValidator->scoreAlbumMatch($detailedData->toArray(), $album),
                'search_results_count' => $searchResults->count(),
                'pagination' => $this->discogsClient->search->getPagination()
            ];

        } catch (\Exception $e) {
            Log::warning('Discogs album search failed', [
                'album_id' => $album->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Advanced multi-strategy search
     */
    public function searchAdvanced(Album $album): array
    {
        Log::debug('Performing advanced search for album', ['album_id' => $album->id]);

        // Strategy 1: Exact title + artist
        $exactFilter = new MusicBrainzReleaseFilter();
        $exactFilter->setTitle($album->title);
        if ($album->artists->isNotEmpty()) {
            $exactFilter->setArtistName($album->artists->first()->name);
        }
        $exactResults = $this->musicBrainzClient->search->release($exactFilter);

        // Strategy 2: Title only (fuzzy)
        $fuzzyFilter = new MusicBrainzReleaseFilter();
        $fuzzyFilter->setTitle($album->title);
        $fuzzyResults = $this->musicBrainzClient->search->release($fuzzyFilter);

        // Strategy 3: Artist-based
        $artistResults = collect();
        if ($album->artists->isNotEmpty()) {
            $artistFilter = new MusicBrainzReleaseFilter();
            $artistFilter->setArtistName($album->artists->first()->name);
            $artistResults = $this->musicBrainzClient->search->release($artistFilter);
        }

        // Combine and deduplicate
        $allResults = $exactResults
            ->merge($fuzzyResults)
            ->merge($artistResults)
            ->unique('id')
            ->filter(fn($release) => $this->isRelevantMatch($release, $album))
            ->sortByDesc(fn($release) => $this->calculateRelevanceScore($release, $album));

        return [
            'strategies' => [
                'exact' => ['count' => $exactResults->count(), 'results' => $exactResults->take(3)],
                'fuzzy' => ['count' => $fuzzyResults->count(), 'results' => $fuzzyResults->take(3)],
                'artist' => ['count' => $artistResults->count(), 'results' => $artistResults->take(3)],
            ],
            'combined' => [
                'total_count' => $allResults->count(),
                'top_results' => $allResults->take(10),
                'best_match' => $allResults->first()
            ]
        ];
    }

    private function isRelevantMatch($release, Album $album): bool
    {
        if (empty($release->title)) {
            return false;
        }

        $titleSimilarity = similar_text(
            strtolower($release->title),
            strtolower($album->title)
        );

        return $titleSimilarity > 50;
    }

    private function isRelevantDiscogsMatch($release, Album $album): bool
    {
        if (empty($release->title)) {
            return false;
        }

        // Discogs titles often include artist name, so be more flexible
        $titleSimilarity = similar_text(
            strtolower($release->title),
            strtolower($album->title)
        );

        return $titleSimilarity > 30;
    }

    private function calculateRelevanceScore($release, Album $album): float
    {
        $score = 0;

        // Title match (0-100)
        $score += similar_text(strtolower($release->title), strtolower($album->title));

        // Artist match if available
        if (!empty($release->artist_credit) && $album->artists->isNotEmpty()) {
            $releaseArtist = $release->artist_credit[0]['artist']['name'] ?? '';
            $albumArtist = $album->artists->first()->name;
            $score += similar_text(strtolower($releaseArtist), strtolower($albumArtist)) * 0.5;
        }

        // Year match if available
        if (!empty($release->date) && !empty($album->year)) {
            $releaseYear = substr($release->date, 0, 4);
            if ($releaseYear == $album->year) {
                $score += 30;
            }
        }

        return $score;
    }

    private function calculateDiscogsRelevanceScore($release, Album $album): float
    {
        $score = 0;

        // Title match (more flexible for Discogs)
        $score += similar_text(strtolower($release->title), strtolower($album->title)) * 0.8;

        // Artist match
        if (isset($release->artists) && $album->artists->isNotEmpty()) {
            $releaseArtist = is_array($release->artists) ? ($release->artists[0]['name'] ?? '') : $release->artists;
            $albumArtist = $album->artists->first()->name;
            $score += similar_text(strtolower($releaseArtist), strtolower($albumArtist)) * 0.3;
        }

        // Year match
        if (!empty($release->year) && !empty($album->year)) {
            if ($release->year == $album->year) {
                $score += 40;
            }
        }

        return $score;
    }
}