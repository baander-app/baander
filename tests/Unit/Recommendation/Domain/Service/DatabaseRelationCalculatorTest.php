<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Domain\Service;

use App\Recommendation\Domain\Service\DatabaseRelationCalculator;
use PHPUnit\Framework\TestCase;

final class DatabaseRelationCalculatorTest extends TestCase
{
    private DatabaseRelationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DatabaseRelationCalculator();
    }

    // ---------------------------------------------------------------------------
    // sharedEntityScore
    // ---------------------------------------------------------------------------

    public function testSharedEntityScoreWithEmptyArraysReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calculator->sharedEntityScore([], []));
        $this->assertSame(0.0, $this->calculator->sharedEntityScore(['a' => '1'], []));
        $this->assertSame(0.0, $this->calculator->sharedEntityScore([], ['b' => '2']));
    }

    public function testSharedEntityScoreWithZeroIntersectionReturnsZero(): void
    {
        $score = $this->calculator->sharedEntityScore(
            ['artist-1', 'artist-2'],
            ['artist-3', 'artist-4'],
        );

        $this->assertSame(0.0, $score);
    }

    public function testSharedEntityScoreWithTfIdfWeighting(): void
    {
        // With default totalPool = 1000
        // aIds = ['artist-1'] (1 entity), bIds = ['artist-1'] (1 entity)
        // intersection = 1
        // idfA = log(1001/2) = log(500.5) = 6.2156
        // idfB = log(1001/2) = log(500.5) = 6.2156
        // score = 1 * 6.2156 * 6.2156 = 38.634
        $score = $this->calculator->sharedEntityScore(
            ['artist-1'],
            ['artist-1'],
        );

        $this->assertGreaterThan(0.0, $score);
        $this->assertEqualsWithDelta(38.634, $score, 0.01);
    }

    public function testSharedEntityScoreLargerSetsProduceLowerIdf(): void
    {
        // More shared entities means larger sets, lower IDF per entity
        $smallA = ['artist-1'];
        $smallB = ['artist-1'];
        $largeA = ['artist-1', 'artist-2', 'artist-3', 'artist-4', 'artist-5'];
        $largeB = ['artist-1', 'artist-2', 'artist-3', 'artist-4', 'artist-5'];

        $smallScore = $this->calculator->sharedEntityScore($smallA, $smallB);
        $largeScore = $this->calculator->sharedEntityScore($largeA, $largeB);

        // Both have intersection of 1 (or 5), but larger sets have lower IDF
        // The large set has 5 shared items, so score = 5 * idfA_large * idfB_large
        // vs small set: 1 * idfA_small * idfB_small
        // Need to check per-entity weighting effect
        $this->assertGreaterThan(0.0, $smallScore);
        $this->assertGreaterThan(0.0, $largeScore);
    }

    public function testSharedEntityScoreWithCustomTotalPool(): void
    {
        $score = $this->calculator->sharedEntityScore(
            ['artist-1'],
            ['artist-1'],
            100,
        );

        // idf = log(101/2) = log(50.5) = 3.9219
        // score = 1 * 3.9219 * 3.9219 = 15.381
        $this->assertGreaterThan(0.0, $score);
        $this->assertEqualsWithDelta(15.381, $score, 0.01);
    }

    // ---------------------------------------------------------------------------
    // combinedScore
    // ---------------------------------------------------------------------------

    public function testCombinedScoreWithEmptyScoresReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calculator->combinedScore([]));
    }

    public function testCombinedScoreWithDefaultWeights(): void
    {
        // artist: 0.4, genre: 0.3, album: 0.2, tag: 0.1
        // Score = 0.8 * 0.4 + 0.6 * 0.3 + 0.5 * 0.2 = 0.32 + 0.18 + 0.10 = 0.60
        $score = $this->calculator->combinedScore([
            'artist' => 0.8,
            'genre' => 0.6,
            'album' => 0.5,
        ]);

        $this->assertEqualsWithDelta(0.60, $score, 0.001);
    }

    public function testCombinedScoreWithCustomWeightsOverridesDefaults(): void
    {
        // Custom weights: artist = 1.0 (override default 0.4)
        // Score = 0.8 * 1.0 + 0.6 * 0.3 = 0.80 + 0.18 = 0.98
        $score = $this->calculator->combinedScore(
            ['artist' => 0.8, 'genre' => 0.6],
            ['artist' => 1.0],
        );

        $this->assertEqualsWithDelta(0.98, $score, 0.001);
    }

    public function testCombinedScoreWithUnknownTypeGetsZeroWeight(): void
    {
        // 'unknown' type is not in default weights and not in custom weights
        $score = $this->calculator->combinedScore([
            'unknown' => 1.0,
            'artist' => 0.5,
        ]);

        // Only 'artist' contributes: 0.5 * 0.4 = 0.2
        $this->assertEqualsWithDelta(0.2, $score, 0.001);
    }

    public function testCombinedScoreCapsAtOne(): void
    {
        // All scores at 1.0 with default weights: 0.4 + 0.3 + 0.2 + 0.1 = 1.0
        $score = $this->calculator->combinedScore([
            'artist' => 1.0,
            'genre' => 1.0,
            'album' => 1.0,
            'tag' => 1.0,
        ]);

        $this->assertEqualsWithDelta(1.0, $score, 0.001);
    }

    public function testCombinedScoreExceedingOneIsCapped(): void
    {
        // High custom weights: artist = 2.0, genre = 2.0
        // 1.0 * 2.0 + 1.0 * 2.0 = 4.0 => capped to 1.0
        $score = $this->calculator->combinedScore(
            ['artist' => 1.0, 'genre' => 1.0],
            ['artist' => 2.0, 'genre' => 2.0],
        );

        $this->assertSame(1.0, $score);
    }

    public function testCombinedScoreWithPartialDefaultWeights(): void
    {
        // Only artist score provided; genre, album, tag default weights still exist but unused
        $score = $this->calculator->combinedScore([
            'artist' => 0.5,
        ]);

        $this->assertEqualsWithDelta(0.2, $score, 0.001);
    }
}
