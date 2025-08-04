<?php

namespace App\Modules\Metadata\Matching\Validators;

use App\Models\Artist;
use App\Models\BaseModel;
use Illuminate\Support\Facades\Log;

class ArtistQualityValidator extends BaseQualityValidator
{
    private const float MIN_QUALITY_THRESHOLD = 0.3;
    private const float HIGH_CONFIDENCE_THRESHOLD = 0.8;

    public function scoreMatch(array $metadata, BaseModel|Artist $model): float
    {
        $score = 0;
        $maxScore = 0;

        Log::debug('Starting artist quality score calculation', [
            'artist_id' => $model->id,
            'artist_name' => $model->name,
            'metadata_keys' => array_keys($metadata),
        ]);

        // Enhanced name similarity with multiple strategies (40% weight)
        $nameScore = $this->scoreEnhancedNameSimilarity($metadata, $model);
        $score += $nameScore;
        $maxScore += 4.0;

        // Artist details with weighted importance (30% weight)
        $detailsScore = $this->scoreArtistDetails($metadata);
        $score += $detailsScore;
        $maxScore += 3.0;

        // Discography/releases with contextual scoring (20% weight)
        $discographyScore = $this->scoreDiscography($metadata);
        $score += $discographyScore;
        $maxScore += 2.0;

        // Additional metadata (10% weight)
        $additionalScore = $this->scoreAdditionalMetadata($metadata);
        $score += $additionalScore;
        $maxScore += 1.0;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Artist quality score calculation complete', [
            'artist_id' => $model->id,
            'name_score' => $nameScore,
            'details_score' => $detailsScore,
            'discography_score' => $discographyScore,
            'additional_score' => $additionalScore,
            'final_score' => round($finalScore, 2),
        ]);

        return round($finalScore, 2);
    }

    private function scoreEnhancedNameSimilarity(array $metadata, Artist $artist): float
    {
        if (empty($metadata['name'])) {
            return 0;
        }

        $metadataName = $metadata['name'];
        $artistName = $artist->name;

        // Use the TextSimilarityService for international name comparison
        $scores = $this->textSimilarity->calculateInternationalNameSimilarity($metadataName, $artistName);

        // Take the best score but give preference to direct matches
        $bestScore = max($scores);
        if ($scores['direct'] >= 85) {
            $bestScore = $scores['direct']; // Prefer exact matches
        }

        $finalScore = ($bestScore / 100) * 4.0;

        Log::debug('Enhanced international name similarity breakdown', [
            'metadata_name' => $metadataName,
            'artist_name' => $artistName,
            'scores' => $scores,
            'best_score' => $bestScore,
            'final_score' => $finalScore,
        ]);

        return $finalScore;
    }

    // Enhanced artist details scoring with contextual weights
    private function scoreArtistDetails(array $metadata): float
    {
        $score = 0;
        $detailFields = [
            'type' => 1.0,           // Most important - Person vs Group
            'country' => 0.8,        // Geographic context
            'life-span' => 0.9,      // Timeline context
            'disambiguation' => 0.6, // Helpful for common names
            'gender' => 0.4,         // Less reliable, lower weight
            'area' => 0.5,           // Regional context
            'begin-area' => 0.3,     // Birth place
            'end-area' => 0.2,       // Less relevant
        ];

        foreach ($detailFields as $field => $weight) {
            if (!empty($metadata[$field])) {
                $fieldScore = $this->scoreSpecificField($field, $metadata[$field], $weight);
                $score += $fieldScore;
                Log::debug("Artist detail '{$field}' scored {$fieldScore} points");
            }
        }

        return min($score, 3.0); // Cap at max weight
    }

    private function scoreSpecificField(string $field, $value, float $baseWeight): float
    {
        switch ($field) {
            case 'type':
                // Higher score for specific types vs generic ones
                $specificTypes = ['Person', 'Group', 'Orchestra', 'Choir'];
                return in_array($value, $specificTypes) ? $baseWeight : $baseWeight * 0.7;

            case 'life-span':
                // Score based on completeness of life span data
                if (is_array($value)) {
                    $hasBegin = !empty($value['begin']);
                    $hasEnd = !empty($value['end']);
                    return $baseWeight * (($hasBegin ? 0.6 : 0) + ($hasEnd ? 0.4 : 0));
                }
                return $baseWeight * 0.5;

            case 'country':
                // Valid country codes get full score
                return strlen($value) === 2 ? $baseWeight : $baseWeight * 0.8;

            default:
                return $baseWeight;
        }
    }

    // Enhanced discography scoring with release quality assessment
    private function scoreDiscography(array $metadata): float
    {
        $score = 0;

        if (isset($metadata['releases']) && is_array($metadata['releases'])) {
            $releases = $metadata['releases'];
            $releaseCount = count($releases);

            // Base score for having releases
            $score += min(1.0, $releaseCount * 0.1);

            // Quality bonus for diverse release types
            $releaseTypes = [];
            foreach ($releases as $release) {
                if (isset($release['primary-type'])) {
                    $releaseTypes[] = $release['primary-type'];
                }
            }

            $uniqueTypes = count(array_unique($releaseTypes));
            $score += min(0.5, $uniqueTypes * 0.15); // Bonus for album diversity

            Log::debug("Found {$releaseCount} releases with {$uniqueTypes} unique types");
        }

        if (isset($metadata['release-groups']) && is_array($metadata['release-groups'])) {
            $score += 0.4;
            Log::debug('Release groups present - added 0.4 points');
        }

        // Penalize artists with too few releases (might be false matches)
        if ($score < 0.3 && isset($metadata['releases'])) {
            $score *= 0.7; // Reduce score for artists with minimal discography
        }

        return min($score, 2.0);
    }

    // Enhanced additional metadata with relevance weighting
    private function scoreAdditionalMetadata(array $metadata): float
    {
        $score = 0;

        // Tags with genre relevance (check if key exists first)
        if (is_array($metadata['tags']) && !empty($metadata['tags'])) {
            $tagCount = count($metadata['tags']);
            $score += min(0.4, $tagCount * 0.05); // More tags = more context
            Log::debug("Found {$tagCount} tags, scored " . min(0.4, $tagCount * 0.05) . " points");
        }

        // Relations (collaborations, band members, etc.)
        if (isset($metadata['relations']) && is_array($metadata['relations'])) {
            $relationTypes = [];
            foreach ($metadata['relations'] as $relation) {
                if (isset($relation['type'])) {
                    $relationTypes[] = $relation['type'];
                }
            }

            $uniqueRelations = count(array_unique($relationTypes));
            $relationScore = min(0.4, $uniqueRelations * 0.1);
            $score += $relationScore;
            Log::debug("Found {$uniqueRelations} unique relation types, scored {$relationScore} points");
        }

        // Aliases (alternative names)
        if (is_array($metadata['aliases']) && !empty($metadata['aliases'])) {
            $score += 0.15;
            Log::debug("Found aliases, scored 0.15 points");
        }

        // External IDs (indicates established artist)
        $externalIds = ['discogs-id', 'spotify-id', 'lastfm-id', 'allmusic-id'];
        $foundIds = 0;
        foreach ($externalIds as $idField) {
            if (!empty($metadata[$idField])) {
                $score += 0.05;
                $foundIds++;
            }
        }
        if ($foundIds > 0) {
            Log::debug("Found {$foundIds} external IDs, scored " . ($foundIds * 0.05) . " points");
        }

        // Discogs-specific fields
        if (!empty($metadata['profile'])) {
            $score += 0.1;
            Log::debug("Found profile, scored 0.1 points");
        }

        if (is_array($metadata['images']) && !empty($metadata['images'])) {
            $score += 0.05;
            Log::debug("Found images, scored 0.05 points");
        }

        if (is_array($metadata['urls']) && !empty($metadata['urls'])) {
            $score += 0.05;
            Log::debug("Found URLs, scored 0.05 points");
        }

        Log::debug("Additional metadata total score: {$score}");
        return min($score, 1.0);
    }

    public function isValidMatch(array $metadata, float $qualityScore): bool
    {
        return $qualityScore >= self::MIN_QUALITY_THRESHOLD && !empty($metadata['name']);
    }

    public function isHighConfidenceMatch(array $metadata, BaseModel|Artist $model, float $qualityScore): bool
    {
        if ($qualityScore < self::HIGH_CONFIDENCE_THRESHOLD) {
            return false;
        }

        // High confidence requires very high name similarity
        $nameScore = $this->scoreEnhancedNameSimilarity($metadata, $model);
        if ($nameScore < 3.0) { // 75% of max name score
            return false;
        }

        // Must have significant additional details
        return $this->hasSignificantDetails($metadata);
    }

    private function hasSignificantDetails(array $metadata): bool
    {
        $significantFields = ['type', 'country', 'life-span', 'releases', 'disambiguation'];
        $presentFields = 0;
        $qualityScore = 0;

        foreach ($significantFields as $field) {
            if (!empty($metadata[$field])) {
                $presentFields++;

                // Weight certain fields higher
                switch ($field) {
                    case 'releases':
                        if (is_array($metadata[$field]) && count($metadata[$field]) >= 3) {
                            $qualityScore += 2; // Strong indicator
                        } else {
                            $qualityScore += 1;
                        }
                        break;
                    case 'type':
                    case 'life-span':
                        $qualityScore += 1.5;
                        break;
                    default:
                        $qualityScore += 1;
                }
            }
        }

        return $presentFields >= 2 && $qualityScore >= 3;
    }
}