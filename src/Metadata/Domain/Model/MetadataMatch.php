<?php

declare(strict_types=1);

namespace App\Metadata\Domain\Model;

final class MetadataMatch
{
    public function __construct(
        public readonly array $candidate,
        public readonly float $confidence,
        public readonly float $artistScore,
        public readonly float $albumScore,
        public readonly float $songScore,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Confidence must be between 0.0 and 1.0');
        }
    }

    public function getCandidate(): array
    {
        return $this->candidate;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getArtistScore(): float
    {
        return $this->artistScore;
    }

    public function getAlbumScore(): float
    {
        return $this->albumScore;
    }

    public function getSongScore(): float
    {
        return $this->songScore;
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.8;
    }

    public function isMediumConfidence(): bool
    {
        return $this->confidence >= 0.5 && $this->confidence < 0.8;
    }
}
