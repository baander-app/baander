<?php

namespace App\Modules\Metadata\Matching;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\Matching\Validators\ArtistQualityValidator;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class MatchingStrategy
{
    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;
    
    private ArtistQualityValidator $artistQualityValidator;

    public function __construct(ArtistQualityValidator $artistQualityValidator)
    {
        $this->artistQualityValidator = $artistQualityValidator;
    }

    /**
     * Find the best album match from search results with adaptive threshold
     */
    public function findBestAlbumMatch(array $results, Album $album): ?array
    {
        if (empty($results)) {
            return null;
        }

        $this->logger->debug('Finding best album match', [
            'album_id'      => $album->id,
            'results_count' => count($results),
            'album_title'   => $album->title,
        ]);

        // Remove duplicates based on title and artist similarity
        $uniqueResults = $this->removeDuplicateResults($results);

        $this->logger->debug('Removed duplicate results', [
            'album_id'       => $album->id,
            'original_count' => count($results),
            'unique_count'   => count($uniqueResults),
        ]);

        // Filter out non-music releases (DVDs, etc.) for MusicBrainz results
        $filteredResults = $this->filterMusicReleases($uniqueResults, $album);

        if (count($filteredResults) < count($uniqueResults)) {
            $this->logger->debug('Filtered out non-music releases', [
                'album_id'      => $album->id,
                'before_filter' => count($uniqueResults),
                'after_filter'  => count($filteredResults),
            ]);
        }

        // Calculate adaptive threshold based on metadata completeness
        $metadataCompleteness = 1.0 - $this->calculateMetadataCompletenessBoost($album);
        $baseThreshold = 0.5;
        $threshold = $baseThreshold * $metadataCompleteness;
        $threshold = max(0.3, $threshold);

        $this->logger->debug('Adaptive threshold calculated', [
            'album_id'              => $album->id,
            'metadata_completeness' => $metadataCompleteness,
            'base_threshold'        => $baseThreshold,
            'adaptive_threshold'    => $threshold,
        ]);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($filteredResults as $index => $result) {
            $score = $this->calculateAlbumMatchScore($result, $album);

            $this->logger->debug('Album match score calculated', [
                'album_id'     => $album->id,
                'result_index' => $index,
                'result_title' => $result['title'] ?? 'Unknown',
                'score'        => $score,
                'threshold'    => $threshold,
            ]);

            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestMatch = $result;
                $bestMatch['match_score'] = $score;

                if ($score >= 0.95) {
                    $this->logger->debug('Found excellent match, stopping search early', [
                        'album_id'          => $album->id,
                        'match_title'       => $result['title'] ?? 'Unknown',
                        'final_score'       => $score,
                        'evaluated_results' => $index + 1,
                        'total_results'     => count($filteredResults),
                    ]);
                    break;
                }
            }
        }

        if ($bestMatch) {
            $this->logger->debug('Best match found', [
                'album_id'       => $album->id,
                'match_title'    => $bestMatch['title'] ?? 'Unknown',
                'final_score'    => $bestScore,
                'used_threshold' => $threshold,
            ]);
        } else {
            $this->logger->debug('No suitable match found', [
                'album_id'          => $album->id,
                'threshold'         => $threshold,
                'evaluated_results' => count($filteredResults),
            ]);
        }

        return $bestMatch;
    }

    private function removeDuplicateResults(array $results): array
    {
        $seen = [];
        $unique = [];

        foreach ($results as $result) {
            $title = $this->normalizeString($result['title'] ?? '');
            $artist = '';

            if (isset($result['artist-credit'][0]['artist']['name'])) {
                $artist = $this->normalizeString($result['artist-credit'][0]['artist']['name']);
            } elseif (isset($result['artists'][0])) {
                if (is_array($result['artists'][0])) {
                    $artist = $this->normalizeString($result['artists'][0]['name'] ?? '');
                } else {
                    $artist = $this->normalizeString($result['artists'][0]);
                }
            } elseif (isset($result['title']) && str_contains($result['title'], ' - ')) {
                $parts = explode(' - ', $result['title'], 2);
                $artist = $this->normalizeString(trim($parts[0]));
            }

            $key = $title . '|' . $artist;

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $result;
            }
        }

        return $unique;
    }

    private function normalizeString(string $string): string
    {
        $normalized = mb_strtolower($string);
        $normalized = preg_replace('/\b(the|a|an)\b/', '', $normalized);
        $normalized = preg_replace('/[^\w\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    private function filterMusicReleases(array $results, Album $album): array
    {
        return array_filter($results, function ($result) use ($album) {
            $title = mb_strtolower($result['title'] ?? '');

            $nonMusicKeywords = [
                'dvd', 'video', 'blu-ray', 'bluray', 'vhs', 'laserdisc',
                'documentary', 'live dvd', 'concert dvd', 'music video',
            ];

            foreach ($nonMusicKeywords as $keyword) {
                if (str_contains($title, $keyword)) {
                    $this->logger->debug('Filtering out non-music release', [
                        'album_id'       => $album->id,
                        'filtered_title' => $result['title'],
                        'reason'         => "Contains keyword: {$keyword}",
                    ]);
                    return false;
                }
            }

            if (isset($result['primary-type'])) {
                $primaryType = mb_strtolower($result['primary-type']);
                $allowedTypes = ['album', 'ep', 'single', 'compilation', 'soundtrack', 'other'];

                if (!in_array($primaryType, $allowedTypes)) {
                    $this->logger->debug('Filtering out non-music release by type', [
                        'album_id'       => $album->id,
                        'filtered_title' => $result['title'],
                        'primary_type'   => $result['primary-type'],
                    ]);
                    return false;
                }
            }

            if (isset($result['secondary-types']) && is_array($result['secondary-types'])) {
                $secondaryTypes = array_map('strtolower', $result['secondary-types']);
                $videoTypes = ['video', 'documentary'];

                foreach ($videoTypes as $videoType) {
                    if (in_array($videoType, $secondaryTypes, true)) {
                        $this->logger->debug('Filtering out video release by secondary type', [
                            'album_id'        => $album->id,
                            'filtered_title'  => $result['title'],
                            'secondary_types' => $result['secondary-types'],
                        ]);

                        return false;
                    }
                }
            }

            return true;
        });
    }

    private function calculateMetadataCompletenessBoost(Album $album): float
    {
        $missingMetadataScore = 0;
        $maxMissingScore = 0;

        if (!$album->year) {
            $missingMetadataScore += 0.3;
        }
        $maxMissingScore += 0.3;

        if (!$album->mbid && !$album->discogs_id) {
            $missingMetadataScore += 0.2;
        }
        $maxMissingScore += 0.2;

        if (!$this->albumHasCover($album)) {
            $missingMetadataScore += 0.1;
        }
        $maxMissingScore += 0.1;

        $songMetadataScore = $this->analyzeSongMetadataCompleteness($album);
        $missingMetadataScore += $songMetadataScore * 0.4;
        $maxMissingScore += 0.4;

        $boost = $maxMissingScore > 0 ? $missingMetadataScore / $maxMissingScore : 0;

        $this->logger->debug('Metadata completeness analysis', [
            'album_id'             => $album->id,
            'missing_year'         => !$album->year,
            'missing_external_ids' => !$album->mbid && !$album->discogs_id,
            'missing_cover'        => !$this->albumHasCover($album),
            'song_metadata_score'  => $songMetadataScore,
            'total_boost'          => $boost,
        ]);

        return $boost;
    }

    private function albumHasCover(Album $album): bool
    {
        if ($album->cover()->exists()) {
            return true;
        }

        if (method_exists($album, 'getMedia') && $album->getMedia('cover')->isNotEmpty()) {
            return true;
        }

        return false;
    }

    private function analyzeSongMetadataCompleteness(Album $album): float
    {
        $songs = $album->songs;

        if ($songs->isEmpty()) {
            return 1.0;
        }

        $totalIssues = 0;
        $maxIssues = 0;

        foreach ($songs as $song) {
            $songIssues = 0;
            $maxSongIssues = 0;

            if (!$song->length || $song->length <= 0) {
                ++$songIssues;
            }
            ++$maxSongIssues;

            if (!$song->track || $song->track <= 0) {
                ++$songIssues;
            }
            ++$maxSongIssues;

            if ($song->genres->isEmpty()) {
                $songIssues += 0.5;
            }
            $maxSongIssues += 0.5;

            if (!$song->mbid && !$song->isrc) {
                $songIssues += 0.5;
            }
            $maxSongIssues += 0.5;

            $totalIssues += $maxSongIssues > 0 ? $songIssues / $maxSongIssues : 0;
            ++$maxIssues;
        }

        return $maxIssues > 0 ? $totalIssues / $maxIssues : 0;
    }

    private function calculateAlbumMatchScore(array $result, Album $album): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (45%)
        $titleSimilarity = $this->calculateStringSimilarity(
            $result['title'] ?? '',
            $album->title
        );
        $score += $titleSimilarity * 0.45;
        $maxScore += 0.45;

        // Track count compatibility (15%)
        $trackScore = $this->calculateTrackCompatibilityScore($result, $album);
        $score += $trackScore * 0.15;
        $maxScore += 0.15;

        // Year match (15%)
        $yearScore = $this->calculateYearScore($result, $album);
        $score += $yearScore * 0.15;
        $maxScore += 0.15;

        // Artist similarity (15%)
        $artistScore = 0.5;
        if ($album->artists->isNotEmpty()) {
            $artistScore = $this->calculateArtistSimilarity($result, $album);
        }
        $score += $artistScore * 0.15;
        $maxScore += 0.15;

        // Metadata completeness boost (10%)
        $metadataBoost = $this->calculateMetadataCompletenessBoost($album);
        $score += $metadataBoost * 0.1;
        $maxScore += 0.1;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        $this->logger->debug('Album match score breakdown', [
            'album_id'       => $album->id,
            'title_score'    => $titleSimilarity,
            'track_score'    => $trackScore,
            'year_score'     => $yearScore,
            'artist_score'   => $artistScore,
            'metadata_boost' => $metadataBoost,
            'final_score'    => $finalScore,
        ]);

        return $finalScore;
    }

    private function calculateYearScore(array $result, Album $album): float
    {
        if (!$album->year) {
            return 0.5;
        }

        $resultYear = null;
        if (isset($result['date'])) {
            $resultYear = (int)substr($result['date'], 0, 4);
        } elseif (isset($result['year'])) {
            $resultYear = $result['year'];
        }

        if (!$resultYear) {
            return 0.5;
        }

        $diff = abs($resultYear - $album->year);
        if ($diff === 0) {
            return 1.0;
        }

        if ($diff <= 2) {
            return 0.7;
        }

        if ($diff <= 5) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        $str1 = $this->normalizeString($str1);
        $str2 = $this->normalizeString($str2);

        if ($str1 === $str2) {
            return 1.0;
        }

        $maxLength = max(mb_strlen($str1), mb_strlen($str2));
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        $similarity = max(0, 1 - ($distance / $maxLength));

        // Apply bonus for partial matches
        if (str_contains($str2, $str1) || str_contains($str1, $str2)) {
            $similarity = max($similarity, 0.8);
        }

        return $similarity;
    }

    private function calculateTrackCompatibilityScore(array $result, Album $album): float
    {
        $localTrackCount = $album->songs->count();
        $resultTrackCount = $this->extractTrackCount($result);

        if (!$resultTrackCount) {
            return 0.5;
        }

        if ($localTrackCount === $resultTrackCount) {
            return 1.0;
        }

        $diff = abs($localTrackCount - $resultTrackCount);
        $maxCount = max($localTrackCount, $resultTrackCount);
        $similarity = 1.0 - ($diff / $maxCount);

        return max(0.1, $similarity);
    }

    private function extractTrackCount(array $result): ?int
    {
        if (isset($result['track_count'])) {
            return (int)$result['track_count'];
        }

        if (isset($result['media']) && is_array($result['media'])) {
            $totalTracks = 0;
            foreach ($result['media'] as $medium) {
                if (isset($medium['track-count'])) {
                    $totalTracks += (int)$medium['track-count'];
                } elseif (isset($medium['tracks']) && is_array($medium['tracks'])) {
                    $totalTracks += count($medium['tracks']);
                }
            }
            return $totalTracks > 0 ? $totalTracks : null;
        }

        if (isset($result['tracklist']) && is_array($result['tracklist'])) {
            return count($result['tracklist']);
        }

        return null;
    }

    private function calculateArtistSimilarity(array $result, Album $album): float
    {
        $albumArtists = $album->artists->pluck('name')->toArray();

        if (empty($albumArtists)) {
            return 0.5;
        }

        $resultArtists = $this->extractArtistNames($result);

        if (empty($resultArtists)) {
            return 0.5;
        }

        $bestSimilarity = 0;
        foreach ($albumArtists as $albumArtist) {
            foreach ($resultArtists as $resultArtist) {
                $similarity = $this->calculateStringSimilarity($albumArtist, $resultArtist);
                $bestSimilarity = max($bestSimilarity, $similarity);
            }
        }

        return $bestSimilarity;
    }

    private function extractArtistNames(array $result): array
    {
        $artists = [];

        if (isset($result['artist-credit'])) {
            foreach ($result['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $artists[] = $credit['artist']['name'];
                }
            }
        } elseif (isset($result['artists']) && is_array($result['artists'])) {
            foreach ($result['artists'] as $artist) {
                if (is_array($artist) && isset($artist['name'])) {
                    $artists[] = $artist['name'];
                } elseif (is_string($artist)) {
                    $artists[] = $artist;
                }
            }
        } elseif (isset($result['title']) && str_contains($result['title'], ' - ')) {
            $parts = explode(' - ', $result['title'], 2);
            if (count($parts) === 2) {
                $artists[] = trim($parts[0]);
            }
        }

        return $artists;
    }

    public function findBestArtistMatch(array $results, Artist $artist): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = $this->artistQualityValidator->scoreMatch($result, $artist);

            if ($score > $bestScore && $score >= 0.3) {
                $bestScore = $score;
                $bestMatch = $result;
                $bestMatch['match_score'] = $score;
            }
        }

        return $bestMatch;
    }

    public function findBestSongMatch(array $results, Song $song): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = $this->calculateSongMatchScore($result, $song);

            if ($score > $bestScore && $score >= 0.6) {
                $bestScore = $score;
                $bestMatch = $result;
                $bestMatch['match_score'] = $score;
            }
        }

        return $bestMatch;
    }

    private function calculateSongMatchScore(array $result, Song $song): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (40%)
        $titleSimilarity = $this->calculateStringSimilarity(
            $result['title'] ?? '',
            $song->title
        );
        $score += $titleSimilarity * 0.4;
        $maxScore += 0.4;

        // Artist similarity (30%)
        $artistScore = 0.5;
        if ($song->artists->isNotEmpty()) {
            $artistScore = $this->calculateSongArtistSimilarity($result, $song);
        }
        $score += $artistScore * 0.3;
        $maxScore += 0.3;

        // Duration similarity (30%)
        $durationScore = $this->calculateDurationSimilarity($result, $song);
        $score += $durationScore * 0.3;
        $maxScore += 0.3;

        return $maxScore > 0 ? $score / $maxScore : 0;
    }

    private function calculateSongArtistSimilarity(array $result, Song $song): float
    {
        $songArtists = $song->artists->pluck('name')->toArray();
        $resultArtists = $this->extractArtistNames($result);

        if (empty($songArtists) || empty($resultArtists)) {
            return 0.5;
        }

        $bestSimilarity = 0;
        foreach ($songArtists as $songArtist) {
            foreach ($resultArtists as $resultArtist) {
                $similarity = $this->calculateStringSimilarity($songArtist, $resultArtist);
                $bestSimilarity = max($bestSimilarity, $similarity);
            }
        }

        return $bestSimilarity;
    }

    private function calculateDurationSimilarity(array $result, Song $song): float
    {
        if (!$song->length || !isset($result['length'])) {
            return 0.5;
        }

        $songDuration = $song->length;
        $resultDuration = (int)$result['length'] / 1000;

        $diff = abs($songDuration - $resultDuration);

        if ($diff <= 10) {
            return 1.0;
        }

        if ($diff <= 30) {
            return 0.8;
        }

        if ($diff <= 60) {
            return 0.6;
        }

        return 0.2;
    }
}