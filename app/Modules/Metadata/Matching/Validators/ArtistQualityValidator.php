<?php

namespace App\Modules\Metadata\Matching\Validators;

use App\Models\Artist;
use App\Models\BaseModel;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Psr\Log\LoggerInterface;

class ArtistQualityValidator extends BaseQualityValidator
{
    private const float MIN_QUALITY_THRESHOLD = 0.3;
    private const float HIGH_CONFIDENCE_THRESHOLD = 0.8;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function scoreMatch(array $metadata, BaseModel|Artist $model): float
    {
        $score = 0;
        $maxScore = 0;

        $this->logger->debug('Starting artist quality score calculation', [
            'artist_id'     => $model->id,
            'artist_name'   => $model->name,
            'metadata_keys' => array_keys($metadata),
        ]);

        // Enhanced name similarity with increased weight (50% weight - was 40%)
        $nameScore = $this->scoreEnhancedNameSimilarity($metadata, $model);
        $score += $nameScore;
        $maxScore += 5.0; // Increased from 4.0

        // Artist details with increased weight (35% weight - was 30%)
        $detailsScore = $this->scoreArtistDetails($metadata);
        $score += $detailsScore;
        $maxScore += 3.5; // Increased from 3.0

        // Discography/releases with reduced weight (10% weight - was 20%)
        $discographyScore = $this->scoreDiscography($metadata);
        $score += $discographyScore;
        $maxScore += 1.0; // Reduced from 2.0

        // Additional metadata with reduced weight (5% weight - was 10%)
        $additionalScore = $this->scoreAdditionalMetadata($metadata);
        $score += $additionalScore;
        $maxScore += 0.5; // Reduced from 1.0

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        $this->logger->debug('Artist quality score calculation complete', [
            'artist_id'         => $model->id,
            'name_score'        => $nameScore,
            'details_score'     => $detailsScore,
            'discography_score' => $discographyScore,
            'additional_score'  => $additionalScore,
            'final_score'       => round($finalScore, 2),
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

        // Enhanced scoring logic with bonuses
        $bestScore = max($scores);

        // Bonus for exact matches
        if ($scores['direct'] >= 95) {
            $bestScore = min(100, $bestScore + 5); // 5% bonus for near-perfect matches
        } elseif ($scores['direct'] >= 85) {
            $bestScore = min(100, $bestScore + 3); // 3% bonus for high direct matches
        }

        // Apply progressive scoring curve to favor higher similarities
        $normalizedScore = $bestScore / 100;
        $curvedScore = $normalizedScore ** 0.8; // Less aggressive curve than quadratic

        $finalScore = $curvedScore * 5.0; // Updated to match new max score

        $this->logger->debug('Enhanced international name similarity breakdown', [
            'metadata_name' => $metadataName,
            'artist_name'   => $artistName,
            'scores'        => $scores,
            'best_score'    => $bestScore,
            'final_score'   => $finalScore,
        ]);

        return $finalScore;
    }

    // Enhanced artist details scoring with contextual weights
    private function scoreArtistDetails(array $metadata): float
    {
        $score = 0;
        $detailFields = [
            'type' => 1.2,           // Increased - Most important field
            'life-span' => 1.1,      // Increased - Strong identifier
            'country' => 1.0,        // Increased - Geographic context
            'disambiguation' => 0.8, // Increased - Helpful for common names
            'area' => 0.6,           // Increased - Regional context
            'gender' => 0.5,         // Increased - Demographic info
            'begin-area' => 0.4,     // Increased - Birth place
            'end-area' => 0.3,       // Increased - Less relevant but still useful
        ];

        foreach ($detailFields as $field => $weight) {
            if (!empty($metadata[$field])) {
                $fieldScore = $this->scoreSpecificField($field, $metadata[$field], $weight);
                $score += $fieldScore;
                $this->logger->debug("Artist detail '{$field}' scored {$fieldScore} points");
            }
        }

        // Apply bonus for having multiple important fields
        $importantFields = ['type', 'life-span', 'country', 'disambiguation'];
        $presentImportantFields = 0;
        foreach ($importantFields as $field) {
            if (!empty($metadata[$field])) {
                $presentImportantFields++;
            }
        }

        // Bonus for comprehensive metadata
        if ($presentImportantFields >= 3) {
            $score += 0.3; // 30% bonus for having 3+ important fields
            $this->logger->debug("Comprehensive metadata bonus: 0.3 points");
        } elseif ($presentImportantFields >= 2) {
            $score += 0.15; // 15% bonus for having 2+ important fields
            $this->logger->debug("Good metadata bonus: 0.15 points");
        }

        return min($score, 3.5); // Updated to match new max weight
    }

    private function scoreSpecificField(string $field, $value, float $baseWeight): float
    {
        switch ($field) {
            case 'type':
                // Higher score for specific types vs generic ones
                $specificTypes = ['Person', 'Group', 'Orchestra', 'Choir', 'Character'];
                $highValueTypes = ['Person', 'Group']; // Most common and reliable

                if (in_array($value, $highValueTypes, true)) {
                    return $baseWeight; // Full score for high-value types
                } elseif (in_array($value, $specificTypes, true)) {
                    return $baseWeight * 0.9; // Near-full score for other specific types
                }
                return $baseWeight * 0.6; // Reduced score for generic types

            case 'life-span':
                // Enhanced scoring based on completeness and validity
                if (is_array($value)) {
                    $hasBegin = !empty($value['begin']);
                    $hasEnd = !empty($value['end']);
                    $hasEnded = isset($value['ended']) && $value['ended'];

                    $score = 0;
                    if ($hasBegin) {
                        $score += 0.7; // High value for birth/formation date
                    }
                    if ($hasEnd && $hasEnded) {
                        $score += 0.3; // Additional value for end date
                    } elseif (!$hasEnded && !$hasEnd) {
                        $score += 0.2; // Bonus for active artists (no end date, not ended)
                    }

                    return $baseWeight * $score;
                }
                return $baseWeight * 0.4;

            case 'country':
                // Enhanced country validation and scoring
                if (is_string($value)) {
                    if (strlen($value) === 2 && ctype_upper($value)) {
                        // Valid ISO country code
                        return $baseWeight;
                    } elseif (strlen($value) > 2) {
                        // Country name (less reliable but still valuable)
                        return $baseWeight * 0.8;
                    }
                }
                return $baseWeight * 0.5;

            case 'disambiguation':
                // Score based on disambiguation quality
                if (is_string($value) && strlen($value) > 0) {
                    $length = strlen($value);
                    if ($length > 50) {
                        return $baseWeight; // Detailed disambiguation
                    } elseif ($length > 20) {
                        return $baseWeight * 0.8; // Moderate disambiguation
                    } else {
                        return $baseWeight * 0.6; // Brief disambiguation
                    }
                }
                return $baseWeight * 0.3;

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

            $this->logger->debug("Found {$releaseCount} releases with {$uniqueTypes} unique types");
        }

        if (isset($metadata['release-groups']) && is_array($metadata['release-groups'])) {
            $score += 0.4;
            $this->logger->debug('Release groups present - added 0.4 points');
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
        if (isset($metadata['tags']) && !is_array($metadata['tags'])) {
            $tagCount = count($metadata['tags']);
            $score += min(0.4, $tagCount * 0.05); // More tags = more context
            $this->logger->debug("Found {$tagCount} tags, scored " . min(0.4, $tagCount * 0.05) . " points");
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
            $this->logger->debug("Found {$uniqueRelations} unique relation types, scored {$relationScore} points");
        }

        // Aliases (alternative names)
        if (isset($metadata['aliases']) && !is_array($metadata['aliases'])) {
            $score += 0.15;
            $this->logger->debug("Found aliases, scored 0.15 points");
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
            $this->logger->debug("Found {$foundIds} external IDs, scored " . ($foundIds * 0.05) . " points");
        }

        // Discogs-specific fields
        if (!isset($metadata['profile'])) {
            $score += 0.1;
            $this->logger->debug("Found profile, scored 0.1 points");
        }

        if (isset($metadata['images']) && !is_array($metadata['images'])) {
            $score += 0.05;
            $this->logger->debug("Found images, scored 0.05 points");
        }

        if (isset($metadata['urls']) && !is_array($metadata['urls'])) {
            $score += 0.05;
            $this->logger->debug("Found URLs, scored 0.05 points");
        }

        $this->logger->debug("Additional metadata total score: {$score}");
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

        // High confidence requires very high name similarity (adjusted for new scoring)
        $nameScore = $this->scoreEnhancedNameSimilarity($metadata, $model);
        if ($nameScore < 3.75) { // 75% of max name score (5.0)
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