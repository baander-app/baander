<?php

namespace App\Services\MetadataSearch;

use App\Models\Artist;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\MusicBrainz\Filters\ArtistFilter as MusicBrainzArtistFilter;
use App\Http\Integrations\Discogs\Filters\ArtistFilter as DiscogsArtistFilter;
use App\Services\MetadataMatching\{MatchingStrategy, QualityValidator};
use Illuminate\Support\Facades\Log;

class ArtistSearchService
{
    public function __construct(
        private readonly MusicBrainzClient $musicBrainzClient,
        private readonly DiscogsClient $discogsClient,
        private readonly MatchingStrategy $matchingStrategy,
        private readonly QualityValidator $qualityValidator
    ) {}

    /**
     * Search all sources for artist metadata
     */
    public function searchAllSources(Artist $artist): array
    {
        return [
            'musicbrainz' => $this->searchMusicBrainz($artist),
            'discogs' => $this->searchDiscogs($artist),
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
                'source' => 'musicbrainz',
                'data' => $detailedData,
                'quality_score' => $this->qualityValidator->scoreArtistMatch($detailedData->toArray(), $artist),
                'search_results_count' => $searchResults->count()
            ];

        } catch (\Exception $e) {
            Log::warning('MusicBrainz artist search failed', [
                'artist_id' => $artist->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Search Discogs for artist
     */
    public function searchDiscogs(Artist $artist): ?array
    {
        try {
            $filter = new DiscogsArtistFilter();
            $filter->setTitle($artist->name);

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
                'source' => 'discogs',
                'data' => $detailedData,
                'quality_score' => $this->qualityValidator->scoreArtistMatch($detailedData->toArray(), $artist),
                'search_results_count' => $searchResults->count(),
                'pagination' => $this->discogsClient->search->getPagination()
            ];

        } catch (\Exception $e) {
            Log::warning('Discogs artist search failed', [
                'artist_id' => $artist->id,
                'error' => $e->getMessage()
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
            strtolower($artistModel->name),
            strtolower($artist->name)
        );

        return $similarity > 70;
    }

    private function isRelevantDiscogsMatch($artistModel, Artist $artist): bool
    {
        if (empty($artistModel->title)) {
            return false;
        }

        $similarity = similar_text(
            strtolower($artistModel->title),
            strtolower($artist->name)
        );

        return $similarity > 70;
    }

    private function calculateRelevanceScore($artistModel, Artist $artist): float
    {
        return similar_text(strtolower($artistModel->name), strtolower($artist->name));
    }

    private function calculateDiscogsRelevanceScore($artistModel, Artist $artist): float
    {
        return similar_text(strtolower($artistModel->title), strtolower($artist->name));
    }
}