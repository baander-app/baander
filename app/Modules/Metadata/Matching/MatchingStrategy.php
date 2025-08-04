<?php

namespace App\Modules\Metadata\Matching;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use Illuminate\Support\Facades\Log;

class MatchingStrategy
{

    /**
     * Find the best album match from search results with adaptive threshold
     */
    public function findBestAlbumMatch(array $results, Album $album): ?array
    {
        if (empty($results)) {
            return null;
        }

        Log::debug('Finding best album match', [
            'album_id'      => $album->id,
            'results_count' => count($results),
            'album_title'   => $album->title,
        ]);

        // Remove duplicates based on title and artist similarity
        $uniqueResults = $this->removeDuplicateResults($results, $album);

        Log::debug('Removed duplicate results', [
            'album_id'       => $album->id,
            'original_count' => count($results),
            'unique_count'   => count($uniqueResults),
        ]);

        // Filter out non-music releases (DVDs, etc.) for MusicBrainz results
        $filteredResults = $this->filterMusicReleases($uniqueResults, $album);

        if (count($filteredResults) < count($uniqueResults)) {
            Log::debug('Filtered out non-music releases', [
                'album_id'      => $album->id,
                'before_filter' => count($uniqueResults),
                'after_filter'  => count($filteredResults),
            ]);
        }

        // Calculate adaptive threshold based on metadata completeness
        $metadataCompleteness = 1.0 - $this->calculateMetadataCompletenessBoost($album);
        $baseThreshold = 0.5;
        $threshold = $baseThreshold * $metadataCompleteness; // Lower threshold for albums with poor metadata
        $threshold = max(0.3, $threshold); // Never go below 0.3

        Log::debug('Adaptive threshold calculated', [
            'album_id'              => $album->id,
            'metadata_completeness' => $metadataCompleteness,
            'base_threshold'        => $baseThreshold,
            'adaptive_threshold'    => $threshold,
        ]);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($filteredResults as $index => $result) {
            $score = $this->calculateAlbumMatchScore($result, $album);

            Log::debug('Album match score calculated', [
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

                // If we found an excellent match (>= 0.95), stop searching
                if ($score >= 0.95) {
                    Log::debug('Found excellent match, stopping search early', [
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
            Log::debug('Best match found', [
                'album_id'       => $album->id,
                'match_title'    => $bestMatch['title'] ?? 'Unknown',
                'final_score'    => $bestScore,
                'used_threshold' => $threshold,
            ]);
        } else {
            Log::debug('No suitable match found', [
                'album_id'          => $album->id,
                'threshold'         => $threshold,
                'evaluated_results' => count($filteredResults),
            ]);
        }

        return $bestMatch;
    }

    /**
     * Remove duplicate results to improve matching performance
     */
    private function removeDuplicateResults(array $results): array
    {
        $seen = [];
        $unique = [];

        foreach ($results as $result) {
            // Create a key based on normalized title and primary artist
            $title = $this->normalizeString($result['title'] ?? '');
            $artist = '';

            // Extract primary artist for deduplication
            if (isset($result['artist-credit'][0]['artist']['name'])) {
                $artist = $this->normalizeString($result['artist-credit'][0]['artist']['name']);
            } else if (isset($result['artists'][0])) {
                if (is_array($result['artists'][0])) {
                    $artist = $this->normalizeString($result['artists'][0]['name'] ?? '');
                } else {
                    $artist = $this->normalizeString($result['artists'][0]);
                }
            } else if (isset($result['title']) && strpos($result['title'], ' - ') !== false) {
                // Extract artist from title for Discogs results
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
        // Convert to lowercase
        $normalized = strtolower($string);

        // Remove common articles and prepositions (less aggressive)
        $normalized = preg_replace('/\b(the|a|an)\b/', '', $normalized);

        // Remove special characters but keep spaces and alphanumeric
        $normalized = preg_replace('/[^\w\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Filter out non-music releases (DVDs, videos, etc.)
     */
    private function filterMusicReleases(array $results, Album $album): array
    {
        return array_filter($results, function ($result) use ($album) {
            $title = strtolower($result['title'] ?? '');

            // Filter out obvious non-music formats
            $nonMusicKeywords = [
                'dvd',
                'video',
                'blu-ray',
                'bluray',
                'vhs',
                'laserdisc',
                'documentary',
                'live dvd',
                'concert dvd',
                'music video',
            ];

            foreach ($nonMusicKeywords as $keyword) {
                if (str_contains($title, $keyword)) {
                    Log::debug('Filtering out non-music release', [
                        'album_id'       => $album->id,
                        'filtered_title' => $result['title'],
                        'reason'         => "Contains keyword: {$keyword}",
                    ]);
                    return false;
                }
            }

            // For MusicBrainz results, check primary type
            if (isset($result['primary-type'])) {
                $primaryType = strtolower($result['primary-type']);

                // Allow only music album types
                $allowedTypes = ['album', 'ep', 'single', 'compilation', 'soundtrack', 'other'];

                if (!in_array($primaryType, $allowedTypes)) {
                    Log::debug('Filtering out non-music release by type', [
                        'album_id'       => $album->id,
                        'filtered_title' => $result['title'],
                        'primary_type'   => $result['primary-type'],
                    ]);
                    return false;
                }
            }

            // Check secondary types for video/documentary content
            if (isset($result['secondary-types']) && is_array($result['secondary-types'])) {
                $secondaryTypes = array_map('strtolower', $result['secondary-types']);
                $videoTypes = ['video', 'documentary'];

                foreach ($videoTypes as $videoType) {
                    if (in_array($videoType, $secondaryTypes)) {
                        Log::debug('Filtering out video release by secondary type', [
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

    /**
     * Calculate metadata completeness boost
     * Albums with poor metadata get higher scores to encourage external matching
     */
    private function calculateMetadataCompletenessBoost(Album $album): float
    {
        $missingMetadataScore = 0;
        $maxMissingScore = 0;

        // Album-level metadata checks
        if (!$album->year) {
            $missingMetadataScore += 0.3; // Missing year is significant
        }
        $maxMissingScore += 0.3;

        if (!$album->mbid && !$album->discogs_id) {
            $missingMetadataScore += 0.2; // No external IDs
        }
        $maxMissingScore += 0.2;

        // Check if album has cover art
        if (!$this->albumHasCover($album)) {
            $missingMetadataScore += 0.1;
        }
        $maxMissingScore += 0.1;

        // Song-level metadata analysis
        $songMetadataScore = $this->analyzeSongMetadataCompleteness($album);
        $missingMetadataScore += $songMetadataScore * 0.4;
        $maxMissingScore += 0.4;

        $boost = $maxMissingScore > 0 ? $missingMetadataScore / $maxMissingScore : 0;

        Log::debug('Metadata completeness analysis', [
            'album_id'             => $album->id,
            'missing_year'         => !$album->year,
            'missing_external_ids' => !$album->mbid && !$album->discogs_id,
            'missing_cover'        => !$this->albumHasCover($album),
            'song_metadata_score'  => $songMetadataScore,
            'total_boost'          => $boost,
        ]);

        return $boost;
    }

    /**
     * Check if album has cover art
     */
    private function albumHasCover(Album $album): bool
    {
        // Check if album has cover relationship
        if ($album->cover()->exists()) {
            return true;
        }

        // Check if album has media attachments for cover
        if (method_exists($album, 'getMedia') && $album->getMedia('cover')->isNotEmpty()) {
            return true;
        }

        return false;
    }

    /**
     * Analyze song metadata completeness
     */
    private function analyzeSongMetadataCompleteness(Album $album): float
    {
        $songs = $album->songs;

        if ($songs->isEmpty()) {
            return 1.0; // Maximum boost if no songs (needs metadata badly)
        }

        $totalIssues = 0;
        $maxIssues = 0;

        foreach ($songs as $song) {
            $songIssues = 0;
            $maxSongIssues = 0;

            // Missing duration
            if (!$song->length || $song->length <= 0) {
                $songIssues += 1;
            }
            $maxSongIssues += 1;

            // Missing track number
            if (!$song->track || $song->track <= 0) {
                $songIssues += 1;
            }
            $maxSongIssues += 1;

            // No genres
            if ($song->genres->isEmpty()) {
                $songIssues += 0.5;
            }
            $maxSongIssues += 0.5;

            // No external IDs
            if (!$song->mbid && !$song->isrc) {
                $songIssues += 0.5;
            }
            $maxSongIssues += 0.5;

            $totalIssues += $maxSongIssues > 0 ? $songIssues / $maxSongIssues : 0;
            $maxIssues += 1;
        }

        return $maxIssues > 0 ? $totalIssues / $maxIssues : 0;
    }

    /**
     * Calculate album match score with track count analysis and metadata completeness boost
     */
    private function calculateAlbumMatchScore(array $result, Album $album): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (weight: 45% - reduced to make room for metadata boost)
        $titleSimilarity = $this->calculateStringSimilarity(
            $result['title'] ?? '',
            $album->title,
        );
        $score += $titleSimilarity * 0.45;
        $maxScore += 0.45;

        Log::debug('Album title similarity', [
            'album_id'     => $album->id,
            'local_title'  => $album->title,
            'result_title' => $result['title'] ?? '',
            'similarity'   => $titleSimilarity,
        ]);

        // Track count compatibility (weight: 15% - reduced)
        $trackScore = $this->calculateTrackCompatibilityScore($result, $album);
        $score += $trackScore * 0.15;
        $maxScore += 0.15;

        // Year match (weight: 15%)
        $yearScore = 0;
        if ($album->year && isset($result['date'])) {
            $resultYear = (int)substr($result['date'], 0, 4);
            if ($resultYear === $album->year) {
                $yearScore = 1.0;
            } else if (abs($resultYear - $album->year) <= 2) {
                $yearScore = 0.7;
            } else if (abs($resultYear - $album->year) <= 5) {
                $yearScore = 0.4;
            }
        } else if ($album->year && isset($result['year'])) {
            if ($result['year'] === $album->year) {
                $yearScore = 1.0;
            } else if (abs($result['year'] - $album->year) <= 2) {
                $yearScore = 0.7;
            } else if (abs($result['year'] - $album->year) <= 5) {
                $yearScore = 0.4;
            }
        } else {
            $yearScore = 0.5; // Neutral if no year data
        }

        $score += $yearScore * 0.15;
        $maxScore += 0.15;

        // Artist similarity (weight: 15%)
        $artistScore = 0.5; // Default neutral score
        if ($album->artists->isNotEmpty()) {
            $artistScore = $this->calculateArtistSimilarity($result, $album);
        }
        $score += $artistScore * 0.15;
        $maxScore += 0.15;

        // Metadata completeness boost (weight: 10% - NEW)
        $metadataBoost = $this->calculateMetadataCompletenessBoost($album);
        $score += $metadataBoost * 0.1;
        $maxScore += 0.1;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Album match score breakdown', [
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

    /**
     * Calculate string similarity with special handling for album variants
     */
    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        $str1 = $this->normalizeString($str1);
        $str2 = $this->normalizeString($str2);

        // If strings are identical after normalization, return perfect match
        if ($str1 === $str2) {
            return 1.0;
        }

        // Handle special cases for album variants
        $similarity = $this->calculateAlbumVariantSimilarity($str1, $str2);
        if ($similarity > 0) {
            return $similarity;
        }

        // Use Levenshtein distance for general similarity
        $maxLength = max(strlen($str1), strlen($str2));
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return max(0, 1 - ($distance / $maxLength));
    }

    /**
     * Calculate similarity for album variants (deluxe, special edition, etc.)
     */
    private function calculateAlbumVariantSimilarity(string $str1, string $str2): float
    {
        // Extract base title and edition info
        $title1Info = $this->extractTitleAndEdition($str1);
        $title2Info = $this->extractTitleAndEdition($str2);

        // If base titles don't match well, return 0
        $baseTitleSimilarity = $this->calculateBaseSimilarity($title1Info['base'], $title2Info['base']);

        if ($baseTitleSimilarity < 0.8) {
            return 0;
        }

        // Both have editions - check compatibility
        if ($title1Info['edition'] && $title2Info['edition']) {
            $editionSimilarity = $this->calculateBaseSimilarity($title1Info['edition'], $title2Info['edition']);

            // If editions are very similar, give high score
            if ($editionSimilarity >= 0.8) {
                return min(1.0, $baseTitleSimilarity + 0.1);
            }

            // Different editions of same album - still a good match but not perfect
            return $baseTitleSimilarity * 0.9;
        }

        // One has edition, other doesn't - this requires special handling
        // We'll handle this in the main scoring function with track count analysis
        if ($title1Info['edition'] || $title2Info['edition']) {
            return $baseTitleSimilarity; // Return base similarity, let track analysis decide
        }

        return $baseTitleSimilarity;
    }

    /**
     * Extract base title and edition information
     */
    private function extractTitleAndEdition(string $title): array
    {
        $title = trim($title);

        // Common edition patterns
        $editionPatterns = [
            '/\s*\(([^)]*(?:deluxe|special|anniversary|expanded|remaster|limited|collector).*?)\)$/i',
            '/\s*\[([^\]]*(?:deluxe|special|anniversary|expanded|remaster|limited|collector).*?)\]$/i',
            '/\s*-\s*([^-]*(?:deluxe|special|anniversary|expanded|remaster|limited|collector).*?)$/i',
        ];

        foreach ($editionPatterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $baseTitle = trim(preg_replace($pattern, '', $title));
                $edition = trim($matches[1]);

                return [
                    'base'    => $baseTitle,
                    'edition' => $edition,
                ];
            }
        }

        return [
            'base'    => $title,
            'edition' => null,
        ];
    }

    /**
     * Calculate basic string similarity
     */
    private function calculateBaseSimilarity(string $str1, string $str2): float
    {
        $maxLength = max(strlen($str1), strlen($str2));
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return max(0, 1 - ($distance / $maxLength));
    }

    /**
     * Calculate track compatibility score between result and local album
     */
    private function calculateTrackCompatibilityScore(array $result, Album $album): float
    {
        $localTrackCount = $album->songs->count();

        // If we don't have track info for the result, return neutral score
        if (!isset($result['track_count']) && !isset($result['media'])) {
            return 0.5;
        }

        $resultTrackCount = $this->extractTrackCount($result);

        if (!$resultTrackCount) {
            return 0.5; // Neutral if we can't determine track count
        }

        // Analyze edition compatibility based on track counts
        $localTitle = strtolower($album->title);
        $resultTitle = strtolower($result['title'] ?? '');

        $localHasDeluxe = $this->hasDeluxeKeywords($localTitle);
        $resultHasDeluxe = $this->hasDeluxeKeywords($resultTitle);

        // Case 1: Both are deluxe editions - prefer similar track counts
        if ($localHasDeluxe && $resultHasDeluxe) {
            return $this->calculateTrackCountSimilarity($localTrackCount, $resultTrackCount);
        }

        // Case 2: Local is deluxe, result is standard
        if ($localHasDeluxe && !$resultHasDeluxe) {
            // Only match if our track count suggests we might be the deluxe version
            if ($localTrackCount > $resultTrackCount) {
                return 0.8; // Good match - we likely have the deluxe version
            } else {
                return 0.3; // Poor match - our title says deluxe but we don't have extra tracks
            }
        }

        // Case 3: Local is standard, result is deluxe
        if (!$localHasDeluxe && $resultHasDeluxe) {
            // Only match if track counts suggest this could be correct
            if ($resultTrackCount > $localTrackCount) {
                return 0.2; // Poor match - result has more tracks than we do
            } else {
                return 0.7; // Might be mislabeled deluxe edition
            }
        }

        // Case 4: Neither has deluxe keywords - prefer similar track counts
        return $this->calculateTrackCountSimilarity($localTrackCount, $resultTrackCount);
    }

    /**
     * Extract track count from result data
     */
    private function extractTrackCount(array $result): ?int
    {
        // Direct track count field
        if (isset($result['track_count'])) {
            return (int)$result['track_count'];
        }

        // MusicBrainz media format
        if (isset($result['media']) && is_array($result['media'])) {
            $totalTracks = 0;
            foreach ($result['media'] as $medium) {
                if (isset($medium['track-count'])) {
                    $totalTracks += (int)$medium['track-count'];
                } else if (isset($medium['tracks']) && is_array($medium['tracks'])) {
                    $totalTracks += count($medium['tracks']);
                }
            }
            return $totalTracks > 0 ? $totalTracks : null;
        }

        // Discogs format
        if (isset($result['tracklist']) && is_array($result['tracklist'])) {
            return count($result['tracklist']);
        }

        return null;
    }

    /**
     * Check if title contains deluxe/expanded edition keywords
     */
    private function hasDeluxeKeywords(string $title): bool
    {
        $deluxeKeywords = [
            'deluxe',
            'special edition',
            'expanded',
            'anniversary',
            'collector',
            'limited',
            'remaster',
            'bonus',
            'extended',
        ];

        foreach ($deluxeKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate similarity score based on track counts
     */
    private function calculateTrackCountSimilarity(int $localCount, int $resultCount): float
    {
        if ($localCount === 0 || $resultCount === 0) {
            return 0.5; // Neutral if no track data
        }

        // Perfect match
        if ($localCount === $resultCount) {
            return 1.0;
        }

        // Calculate percentage difference
        $diff = abs($localCount - $resultCount);
        $maxCount = max($localCount, $resultCount);
        $similarity = 1.0 - ($diff / $maxCount);

        // Give minimum score of 0.1 to avoid completely ruling out close matches
        return max(0.1, $similarity);
    }

    private function calculateArtistSimilarity(array $result, Album $album): float
    {
        $albumArtists = $album->artists->pluck('name')->toArray();

        if (empty($albumArtists)) {
            return 0.5; // Neutral score if no local artists
        }

        $resultArtists = [];

        // Extract artist names from MusicBrainz format
        if (isset($result['artist-credit'])) {
            foreach ($result['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $resultArtists[] = $credit['artist']['name'];
                }
            }
        } // Extract artist names from Discogs format
        else if (isset($result['artists']) && is_array($result['artists'])) {
            foreach ($result['artists'] as $artist) {
                if (is_array($artist) && isset($artist['name'])) {
                    $resultArtists[] = $artist['name'];
                } else if (is_string($artist)) {
                    $resultArtists[] = $artist;
                }
            }
        } // Try to extract artist from Discogs title format (e.g., "Artist - Album Title")
        else if (isset($result['title'])) {
            $title = $result['title'];
            // Check if title follows "Artist - Album" pattern
            if (strpos($title, ' - ') !== false) {
                $parts = explode(' - ', $title, 2);
                if (count($parts) === 2) {
                    $extractedArtist = trim($parts[0]);
                    $resultArtists[] = $extractedArtist;

                    Log::debug('Extracted artist from title', [
                        'original_title'   => $title,
                        'extracted_artist' => $extractedArtist,
                        'album_artists'    => $albumArtists,
                    ]);
                }
            }
        }

        if (empty($resultArtists)) {
            Log::debug('No artists found in result', [
                'result_keys'  => array_keys($result),
                'result_title' => $result['title'] ?? 'unknown',
            ]);
            return 0.5; // Neutral score if no result artists found
        }

        $similarity = $this->calculateArtistListSimilarity($albumArtists, $resultArtists);

        Log::debug('Artist similarity calculated', [
            'album_artists'  => $albumArtists,
            'result_artists' => $resultArtists,
            'similarity'     => $similarity,
        ]);

        return $similarity;
    }

    private function calculateArtistListSimilarity(array $artists1, array $artists2): float
    {
        $bestSimilarity = 0;

        foreach ($artists1 as $artist1) {
            foreach ($artists2 as $artist2) {
                $similarity = $this->calculateStringSimilarity($artist1, $artist2);
                $bestSimilarity = max($bestSimilarity, $similarity);
            }
        }

        return $bestSimilarity;
    }

    /**
     * Find the best artist match from search results
     */
    public function findBestArtistMatch(array $results, Artist $artist): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = $this->calculateArtistMatchScore($result, $artist);

            if ($score > $bestScore && $score >= 0.6) { // Lowered from 0.8 to 0.6
                $bestScore = $score;
                $bestMatch = $result;
            }
        }

        return $bestMatch;
    }

    private function calculateArtistMatchScore(array $result, Artist $artist): float
    {
        // Name similarity (weight: 100%)
        return $this->calculateStringSimilarity(
            $result['name'] ?? '',
            $artist->name,
        );
    }

    /**
     * Find the best song match from search results
     */
    public function findBestSongMatch(array $results, Song $song): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = $this->calculateSongMatchScore($result, $song);

            if ($score > $bestScore && $score >= 0.6) { // Lowered from 0.75 to 0.6
                $bestScore = $score;
                $bestMatch = $result;
            }
        }

        return $bestMatch;
    }

    /**
     * Calculate song match score with metadata completeness boost
     */
    private function calculateSongMatchScore(array $result, Song $song): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (weight: 35% - reduced)
        $titleSimilarity = $this->calculateStringSimilarity(
            $result['title'] ?? '',
            $song->title,
        );
        $score += $titleSimilarity * 0.35;
        $maxScore += 0.35;

        // Length match (weight: 25%)
        if ($song->length && isset($result['length'])) {
            $lengthDiff = abs((int)$result['length'] - $song->length);
            $tolerance = max(10000, $song->length * 0.1); // 10 seconds or 10% tolerance

            if ($lengthDiff <= $tolerance) {
                $lengthScore = 1 - ($lengthDiff / $tolerance);
                $score += $lengthScore * 0.25;
            }
        }
        $maxScore += 0.25;

        // Artist similarity (weight: 20%)
        if ($song->artists->isNotEmpty()) {
            $artistScore = $this->calculateRecordingArtistSimilarity($result, $song);
            $score += $artistScore * 0.20;
        }
        $maxScore += 0.20;

        // Album/Release context (weight: 10%)
        if ($song->album && isset($result['releases'])) {
            $albumScore = $this->calculateSongAlbumContextScore($result, $song);
            $score += $albumScore * 0.10;
        }
        $maxScore += 0.10;

        // Metadata completeness boost (weight: 10% - NEW)
        $metadataBoost = $this->calculateSongMetadataBoost($song);
        $score += $metadataBoost * 0.10;
        $maxScore += 0.10;

        return $maxScore > 0 ? $score / $maxScore : 0;
    }

    private function calculateRecordingArtistSimilarity(array $result, Song $song): float
    {
        $songArtists = $song->artists->pluck('name')->toArray();

        if (empty($songArtists)) {
            return 0;
        }

        $resultArtists = [];

        if (isset($result['artist-credit'])) {
            foreach ($result['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $resultArtists[] = $credit['artist']['name'];
                }
            }
        }

        if (empty($resultArtists)) {
            return 0;
        }

        return $this->calculateArtistListSimilarity($songArtists, $resultArtists);
    }

    /**
     * Calculate metadata boost for individual songs
     */
    private function calculateSongMetadataBoost(Song $song): float
    {
        $missingMetadataScore = 0;
        $maxMissingScore = 0;

        // Missing duration
        if (!$song->length || $song->length <= 0) {
            $missingMetadataScore += 0.3;
        }
        $maxMissingScore += 0.3;

        // Missing track number
        if (!$song->track || $song->track <= 0) {
            $missingMetadataScore += 0.2;
        }
        $maxMissingScore += 0.2;

        // No external IDs
        if (!$song->mbid && !$song->isrc) {
            $missingMetadataScore += 0.2;
        }
        $maxMissingScore += 0.2;

        // No genres
        if ($song->genres->isEmpty()) {
            $missingMetadataScore += 0.2;
        }
        $maxMissingScore += 0.2;

        // Missing year (if album also missing year)
        if (!$song->album?->year) {
            $missingMetadataScore += 0.1;
        }
        $maxMissingScore += 0.1;

        return $maxMissingScore > 0 ? $missingMetadataScore / $maxMissingScore : 0;
    }

    /**
     * Calculate similarity between a track result and a song
     */
    public function calculateSongSimilarity(array $trackData, Song $song): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (weight: 50%)
        $titleSimilarity = $this->calculateStringSimilarity(
            $trackData['title'] ?? '',
            $song->title,
        );
        $score += $titleSimilarity * 0.5;
        $maxScore += 0.5;

        // Track position match (weight: 20%)
        if (isset($trackData['position']) && $song->track) {
            $trackPosition = $this->normalizeTrackPosition($trackData['position']);
            if ($trackPosition === $song->track) {
                $score += 0.2;
            } else if (abs($trackPosition - $song->track) <= 1) {
                $score += 0.1; // Close track position gets partial credit
            }
        }
        $maxScore += 0.2;

        // Duration match (weight: 20%)
        if ($song->length) {
            $trackDuration = $this->extractDuration($trackData);
            if ($trackDuration) {
                $durationDiff = abs($trackDuration - $song->length);
                $tolerance = max(10000, $song->length * 0.1); // 10 seconds or 10% tolerance

                if ($durationDiff <= $tolerance) {
                    $score += 0.2 * (1 - ($durationDiff / $tolerance));
                }
            }
        }
        $maxScore += 0.2;

        // Artist match (weight: 10%)
        if ($song->artists->isNotEmpty()) {
            $artistSimilarity = $this->calculateTrackArtistSimilarity($trackData, $song);
            $score += $artistSimilarity * 0.1;
        }
        $maxScore += 0.1;

        return $maxScore > 0 ? $score / $maxScore : 0;
    }

    private function normalizeTrackPosition(string $position): int
    {
        // Handle various track position formats like "A1", "1", "01", etc.
        $position = preg_replace('/[^0-9]/', '', $position);
        return (int)$position ?: 1;
    }

    private function extractDuration(array $trackData): ?int
    {
        if (isset($trackData['length'])) {
            return (int)$trackData['length'];
        }

        if (isset($trackData['duration'])) {
            return $this->parseDuration($trackData['duration']);
        }

        return null;
    }

    private function parseDuration(string $duration): ?int
    {
        $parts = explode(':', $duration);

        if (count($parts) === 2) {
            return (((int)$parts[0] * 60) + (int)$parts[1]) * 1000;
        } else if (count($parts) === 3) {
            return (((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2]) * 1000;
        }

        return null;
    }

    private function calculateTrackArtistSimilarity(array $trackData, Song $song): float
    {
        $songArtists = $song->artists->pluck('name')->toArray();

        if (empty($songArtists)) {
            return 0;
        }

        $trackArtists = [];

        // Extract artist names from track data
        if (isset($trackData['artists'])) {
            foreach ($trackData['artists'] as $artist) {
                $trackArtists[] = $artist['name'] ?? $artist;
            }
        } else if (isset($trackData['release_artists'])) {
            foreach ($trackData['release_artists'] as $artist) {
                $trackArtists[] = $artist['name'] ?? $artist;
            }
        }

        if (empty($trackArtists)) {
            return 0;
        }

        return $this->calculateArtistListSimilarity($songArtists, $trackArtists);
    }

    private function calculateReleaseContextSimilarity(array $releases, Album $album): float
    {
        $bestSimilarity = 0;

        foreach ($releases as $release) {
            $releaseSimilarity = $this->calculateStringSimilarity(
                $release['title'] ?? '',
                $album->title,
            );

            $bestSimilarity = max($bestSimilarity, $releaseSimilarity);
        }

        return $bestSimilarity;
    }
}