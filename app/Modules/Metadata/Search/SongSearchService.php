<?php

namespace App\Modules\Metadata\Search;

use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter as DiscogsReleaseFilter;
use App\Http\Integrations\MusicBrainz\Filters\RecordingFilter;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Models\Song;
use App\Modules\Metadata\Matching\{QualityValidator};
use App\Modules\Metadata\Matching\MatchingStrategy;
use Illuminate\Support\Facades\Log;

class SongSearchService
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
     * Search with album context for better accuracy
     */
    public function searchWithAlbumContext(Song $song, array $albumMetadata): array
    {
        if (empty($albumMetadata) || ($albumMetadata['quality_score'] ?? 0) === 0) {
            return $this->searchAllSources($song);
        }

        // Extract song data from album metadata
        $songData = $this->findSongInAlbumMetadata($song, $albumMetadata);

        if ($songData) {
            return [
                'song'          => $songData,
                'artists'       => $this->extractSongArtists($songData, $albumMetadata['source']),
                'genres'        => $this->extractSongGenres($songData, $albumMetadata['source']),
                'lyrics'        => null,
                'quality_score' => $this->qualityValidator->scoreSongMatch($songData, $song),
                'source'        => $albumMetadata['source'] . '_album_context',
            ];
        }

        // Fallback to individual search
        return $this->searchAllSources($song);
    }

    /**
     * Search all sources for song metadata
     */
    public function searchAllSources(Song $song): array
    {
        return [
            'musicbrainz' => $this->searchMusicBrainz($song),
            'discogs'     => $this->searchDiscogs($song),
        ];
    }

    /**
     * Search MusicBrainz for song
     */
    public function searchMusicBrainz(Song $song): ?array
    {
        try {
            $filter = new RecordingFilter();
            $filter->setTitle($song->title);

            if ($song->artists->isNotEmpty()) {
                $filter->setArtistName($song->artists->first()->name);
            }

            if ($song->album) {
                $filter->setRelease($song->album->title);
            }

            Log::debug('Searching MusicBrainz for song', [
                'song_id' => $song->id,
                'title'   => $song->title,
                'artist'  => $song->artists->first()->name ?? null,
            ]);

            $searchResults = $this->musicBrainzClient->search->recording($filter);

            if ($searchResults->isEmpty()) {
                return null;
            }

            $relevantResults = $searchResults
                ->filter(fn($recording) => $this->isRelevantMatch($recording, $song))
                ->sortByDesc(fn($recording) => $this->calculateRelevanceScore($recording, $song))
                ->take(5);

            if ($relevantResults->isEmpty()) {
                return null;
            }

            $bestMatch = $relevantResults->first();
            $detailedData = $this->musicBrainzClient->lookup->recording($bestMatch->id);

            return [
                'source'        => 'musicbrainz',
                'data'          => $detailedData->toArray(),
                'quality_score' => $this->qualityValidator->scoreSongMatch($detailedData->toArray(), $song),
            ];

        } catch (\Exception $e) {
            Log::warning('MusicBrainz song search failed', [
                'song_id' => $song->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isRelevantMatch($recording, Song $song): bool
    {
        if (empty($recording->title)) {
            return false;
        }

        $similarity = similar_text(
            mb_strtolower($recording->title),
            mb_strtolower($song->title),
        );

        return $similarity > 70;
    }

    private function calculateRelevanceScore($recording, Song $song): float
    {
        $score = similar_text(mb_strtolower($recording->title), mb_strtolower($song->title));

        // Bonus for artist match
        if (isset($recording->artist_credit) && $song->artists->isNotEmpty()) {
            $recordingArtist = $recording->artist_credit[0]['artist']['name'] ?? '';
            $songArtist = $song->artists->first()->name;
            $score += similar_text(mb_strtolower($recordingArtist), mb_strtolower($songArtist)) * 0.3;
        }

        // Bonus for length match (if available)
        if (isset($recording->length) && $song->length) {
            $lengthDiff = abs($recording->length - $song->length);
            if ($lengthDiff < 5000) { // within 5 seconds
                $score += 20;
            }
        }

        return $score;
    }

    /**
     * Search Discogs for song (via release context)
     */
    public function searchDiscogs(Song $song): ?array
    {
        try {
            if (!$song->album) {
                return null;
            }

            $filter = new DiscogsReleaseFilter();
            $filter->setTitle($song->album->title);

            if ($song->album->artists->isNotEmpty()) {
                $filter->setArtist($song->album->artists->first()->name);
            }

            Log::debug('Searching Discogs for song via album', [
                'song_id'     => $song->id,
                'song_title'  => $song->title,
                'album_title' => $song->album->title,
            ]);

            $searchResults = $this->discogsClient->search->release($filter);

            if ($searchResults->isEmpty()) {
                return null;
            }

            // Look for the song in each release's tracklist
            // Try up to 10 releases, but handle lookup failures gracefully
            foreach ($searchResults->take(10) as $release) {
                try {
                    $detailedData = $this->discogsClient->lookup->release($release->id);

                    // Skip if lookup failed (e.g., 500 error)
                    if ($detailedData === null) {
                        Log::debug('Discogs lookup failed for release', [
                            'release_id' => $release->id,
                            'title'      => $release->title ?? 'unknown',
                        ]);
                        continue;
                    }

                    $trackData = $this->findTrackInDiscogs($detailedData->toArray(), $song);

                    if ($trackData) {
                        return [
                            'source'        => 'discogs',
                            'data'          => $trackData,
                            'release_data'  => $detailedData->toArray(),
                            'quality_score' => $this->qualityValidator->scoreSongMatch($trackData, $song),
                        ];
                    }
                } catch (\Exception $e) {
                    Log::debug('Exception during Discogs lookup', [
                        'release_id' => $release->id,
                        'error'      => $e->getMessage(),
                    ]);
                    continue; // Try next release
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::warning('Discogs song search failed', [
                'song_id' => $song->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function findTrackInDiscogs(array $releaseData, Song $song): ?array
    {
        if (!isset($releaseData['tracklist'])) {
            return null;
        }

        foreach ($releaseData['tracklist'] as $track) {
            $similarity = $this->matchingStrategy->calculateSongSimilarity($track, $song);

            if ($similarity >= 0.8) {
                // Enhance track data with release context
                $track['release_artists'] = $releaseData['artists'] ?? [];
                $track['release_genres'] = array_merge(
                    $releaseData['genres'] ?? [],
                    $releaseData['styles'] ?? [],
                );
                return $track;
            }
        }

        return null;
    }

    private function findSongInAlbumMetadata(Song $song, array $albumMetadata): ?array
    {
        $data = $albumMetadata['data'] ?? [];
        $source = $albumMetadata['source'];

        return match ($source) {
            'musicbrainz' => $this->findSongInMusicBrainzRelease($data, $song),
            'discogs' => $this->findTrackInDiscogs($data, $song),
            default => null,
        };
    }

    private function findSongInMusicBrainzRelease(array $releaseData, Song $song): ?array
    {
        if (!isset($releaseData['media'])) {
            return null;
        }

        foreach ($releaseData['media'] as $medium) {
            if (!isset($medium['tracks'])) {
                continue;
            }

            foreach ($medium['tracks'] as $track) {
                $similarity = $this->matchingStrategy->calculateSongSimilarity($track, $song);

                if ($similarity >= 0.8) {
                    // Get detailed recording data if available
                    if (isset($track['recording']['id'])) {
                        try {
                            $recordingData = $this->musicBrainzClient->lookup->recording($track['recording']['id']);
                            return array_merge($track, $recordingData->toArray());
                        } catch (\Exception) {
                            // Return basic track data if detailed lookup fails
                        }
                    }
                    return $track;
                }
            }
        }

        return null;
    }

    private function extractSongArtists($songData, string $source): array
    {
        $artists = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($songData['artist-credit'])) {
                    foreach ($songData['artist-credit'] as $credit) {
                        if (isset($credit['artist'])) {
                            $artists[] = [
                                'name'            => $credit['artist']['name'],
                                'external_id'     => $credit['artist']['id'],
                                'external_source' => 'musicbrainz',
                            ];
                        }
                    }
                }
                break;

            case 'discogs':
                if (isset($songData['release_artists'])) {
                    foreach ($songData['release_artists'] as $artist) {
                        $artists[] = [
                            'name'            => $artist['name'] ?? $artist,
                            'external_id'     => $artist['id'] ?? null,
                            'external_source' => 'discogs',
                        ];
                    }
                }
                break;
        }

        return $artists;
    }

    private function extractSongGenres($songData, string $source): array
    {
        $genres = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($songData['genres'])) {
                    foreach ($songData['genres'] as $genre) {
                        $genres[] = $genre['name'] ?? $genre;
                    }
                }
                if (isset($songData['tags'])) {
                    foreach ($songData['tags'] as $tag) {
                        $genres[] = $tag['name'] ?? $tag;
                    }
                }
                break;

            case 'discogs':
                if (isset($songData['release_genres'])) {
                    $genres = array_merge($genres, $songData['release_genres']);
                }
                break;
        }

        return array_unique($genres);
    }

    /**
     * Search for song with fuzzy matching
     */
    public function searchFuzzy(Song $song): array
    {
        $results = [];

        // Try variations of the song title
        $variations = $this->generateSongTitleVariations($song->title);

        foreach ($variations as $variation) {
            // Try MusicBrainz
            $filter = new RecordingFilter();
            $filter->setTitle($variation);

            if ($song->artists->isNotEmpty()) {
                $filter->setArtistName($song->artists->first()->name);
            }

            try {
                $searchResults = $this->musicBrainzClient->search->recording($filter);
                if (!$searchResults->isEmpty()) {
                    $results['musicbrainz_' . $variation] = $searchResults->take(3);
                }
            } catch (\Exception $e) {
                Log::debug('Song fuzzy search variation failed', [
                    'variation' => $variation,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Generate song title variations for fuzzy matching
     */
    private function generateSongTitleVariations(string $title): array
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

        // Remove version info like "(Radio Edit)", "(Live)", "(Remix)", etc.
        $withoutVersion = preg_replace('/\s*\([^)]*(?:edit|live|remix|version|mix|acoustic)[^)]*\)/i', '', $title);
        if ($withoutVersion !== $title) {
            $variations[] = trim($withoutVersion);
        }

        // Remove featuring info
        $withoutFeat = preg_replace('/\s*\(?(?:feat\.?|featuring|ft\.?)\s+[^)]*\)?/i', '', $title);
        if ($withoutFeat !== $title) {
            $variations[] = trim($withoutFeat);
        }

        // Remove duplicates and return
        return array_unique($variations);
    }
}