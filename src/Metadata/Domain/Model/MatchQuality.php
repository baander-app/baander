<?php

declare(strict_types=1);

namespace App\Metadata\Domain\Model;

final class MatchQuality
{
    public function __construct(
        private float $artistScore,
        private float $albumScore,
        private float $songScore,
        private float $overallScore,
        /** @var string[] */
        private array $reasons,
    ) {
        // Ensure scores are within valid range
        $this->artistScore = max(0.0, min(1.0, $artistScore));
        $this->albumScore = max(0.0, min(1.0, $albumScore));
        $this->songScore = max(0.0, min(1.0, $songScore));
        $this->overallScore = max(0.0, min(1.0, $overallScore));
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

    public function getOverallScore(): float
    {
        return $this->overallScore;
    }

    /** @return string[] */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function isAcceptable(float $threshold = 0.6): bool
    {
        return $this->overallScore >= $threshold;
    }
}
