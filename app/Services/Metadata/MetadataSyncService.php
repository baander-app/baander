<?php

namespace App\Services\Metadata;

use App\Models\{Album, Artist, Song};
use App\Services\Metadata\Search\{
    AlbumSearchService,
    ArtistSearchService,
    SongSearchService
};
use App\Services\Metadata\Processing\MetadataProcessor;
use App\Services\MetadataMatching\QualityValidator;
use App\Services\Metadata\LocalMetadataService;
use Illuminate\Support\Facades\Log;

class MetadataSyncService
{
    public function __construct(
        private readonly AlbumSearchService $albumSearchService,
        private readonly ArtistSearchService $artistSearchService,
        private readonly SongSearchService $songSearchService,
        private readonly MetadataProcessor $metadataProcessor,
        private readonly QualityValidator $qualityValidator,
        private readonly LocalMetadataService $localMetadataService
    ) {}

    /**
     * Sync metadata for a specific album
     */
    public function syncAlbum(Album $album): array
    {
        try {
            Log::debug('Starting metadata sync for album', [
                'album_id' => $album->id,
                'title' => $album->title,
                'artists' => $album->artists->pluck('name')->toArray()
            ]);

            $searchResults = $this->albumSearchService->searchAllSources($album);
            $bestMatch = $this->selectBestMatch($searchResults);

            if ($bestMatch) {
                $results = $this->metadataProcessor->processAlbumMetadata($bestMatch, $album);

                Log::debug('Album metadata processed successfully', [
                    'album_id' => $album->id,
                    'source' => $results['source'],
                    'quality_score' => $results['quality_score']
                ]);

                return $results;
            }

            // Fallback to local metadata analysis
            Log::debug('No external match found, using local analysis', ['album_id' => $album->id]);
            return $this->localMetadataService->enhanceAlbumMetadata($album);

        } catch (\Exception $e) {
            Log::error('Album metadata sync failed', [
                'album_id' => $album->id,
                'error' => $e->getMessage()
            ]);

            return $this->getEmptyAlbumResult();
        }
    }

    /**
     * Sync metadata for a specific artist
     */
    public function syncArtist(Artist $artist): array
    {
        try {
            Log::debug('Starting metadata sync for artist', [
                'artist_id' => $artist->id,
                'name' => $artist->name
            ]);

            $searchResults = $this->artistSearchService->searchAllSources($artist);
            $bestMatch = $this->selectBestMatch($searchResults);

            if ($bestMatch) {
                $results = $this->metadataProcessor->processArtistMetadata($bestMatch, $artist);

                Log::debug('Artist metadata processed successfully', [
                    'artist_id' => $artist->id,
                    'source' => $results['source'],
                    'quality_score' => $results['quality_score']
                ]);

                return $results;
            }

            Log::debug('No suitable match found for artist', ['artist_id' => $artist->id]);
            return $this->getEmptyArtistResult();

        } catch (\Exception $e) {
            Log::error('Artist metadata sync failed', [
                'artist_id' => $artist->id,
                'error' => $e->getMessage()
            ]);

            return $this->getEmptyArtistResult();
        }
    }

    /**
     * Sync metadata for a specific song
     */
    public function syncSong(Song $song): array
    {
        try {
            Log::debug('Starting metadata sync for song', [
                'song_id' => $song->id,
                'title' => $song->title,
                'album' => $song->album?->title
            ]);

            $searchResults = $this->songSearchService->searchAllSources($song);
            $bestMatch = $this->selectBestMatch($searchResults);

            if ($bestMatch) {
                $results = $this->metadataProcessor->processSongMetadata($bestMatch, $song);

                Log::debug('Song metadata processed successfully', [
                    'song_id' => $song->id,
                    'source' => $results['source'],
                    'quality_score' => $results['quality_score']
                ]);

                return $results;
            }

            Log::debug('No suitable match found for song', ['song_id' => $song->id]);
            return $this->getEmptySongResult();

        } catch (\Exception $e) {
            Log::error('Song metadata sync failed', [
                'song_id' => $song->id,
                'error' => $e->getMessage()
            ]);

            return $this->getEmptySongResult();
        }
    }

    /**
     * Batch sync entire album with all its songs
     */
    public function syncAlbumComplete(Album $album): array
    {
        Log::info('Starting complete album sync', ['album_id' => $album->id]);

        // 1. Sync album metadata
        $albumResult = $this->syncAlbum($album);

        // 2. Sync artist metadata
        $artistResults = [];
        foreach ($album->artists as $artist) {
            $artistResults[$artist->id] = $this->syncArtist($artist);
        }

        // 3. Sync song metadata (with album context)
        $songResults = [];
        foreach ($album->songs as $song) {
            $songResults[$song->id] = $this->songSearchService->searchWithAlbumContext($song, $albumResult);
        }

        return [
            'album' => $albumResult,
            'artists' => $artistResults,
            'songs' => $songResults,
            'summary' => [
                'total_songs' => count($songResults),
                'successful_songs' => count(array_filter($songResults, fn($r) => ($r['quality_score'] ?? 0) > 0)),
                'total_artists' => count($artistResults),
                'successful_artists' => count(array_filter($artistResults, fn($r) => ($r['quality_score'] ?? 0) > 0)),
                'album_quality' => $albumResult['quality_score'] ?? 0
            ]
        ];
    }

    /**
     * Select the best match from multiple search results
     */
    private function selectBestMatch(array $searchResults): ?array
    {
        $validResults = array_filter($searchResults, fn($result) => $result !== null);

        if (empty($validResults)) {
            return null;
        }

        if (count($validResults) === 1) {
            return reset($validResults);
        }

        // Return the result with the highest quality score
        return collect($validResults)
            ->sortByDesc('quality_score')
            ->first();
    }

    private function getEmptyAlbumResult(): array
    {
        return [
            'album' => null,
            'artists' => [],
            'songs' => [],
            'genres' => [],
            'quality_score' => 0,
            'source' => null,
        ];
    }

    private function getEmptyArtistResult(): array
    {
        return [
            'artist' => null,
            'albums' => [],
            'quality_score' => 0,
            'source' => null,
        ];
    }

    private function getEmptySongResult(): array
    {
        return [
            'song' => null,
            'artists' => [],
            'genres' => [],
            'lyrics' => null,
            'quality_score' => 0,
            'source' => null,
        ];
    }
}