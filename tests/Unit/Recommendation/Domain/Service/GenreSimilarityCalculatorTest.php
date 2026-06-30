<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Domain\Service;

use App\Recommendation\Domain\Service\GenreSimilarityCalculator;
use PHPUnit\Framework\TestCase;

final class GenreSimilarityCalculatorTest extends TestCase
{
    private GenreSimilarityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GenreSimilarityCalculator();
    }

    public function testJaccardSimilarityWithIdenticalSets(): void
    {
        $similarity = $this->calculator->jaccardSimilarity(
            ['Rock', 'Pop', 'Jazz'],
            ['Rock', 'Pop', 'Jazz'],
        );

        $this->assertSame(1.0, $similarity);
    }

    public function testJaccardSimilarityWithDisjointSets(): void
    {
        $similarity = $this->calculator->jaccardSimilarity(
            ['Rock', 'Metal'],
            ['Jazz', 'Classical'],
        );

        $this->assertSame(0.0, $similarity);
    }

    public function testJaccardSimilarityWithPartialOverlap(): void
    {
        // Intersection: {Rock}, Union: {Rock, Pop, Jazz}
        $similarity = $this->calculator->jaccardSimilarity(
            ['Rock', 'Pop'],
            ['Rock', 'Jazz'],
        );

        $this->assertEqualsWithDelta(1.0 / 3.0, $similarity, 0.001);
    }

    public function testJaccardSimilarityWithOneEmptySet(): void
    {
        $similarity = $this->calculator->jaccardSimilarity(['Rock'], []);

        $this->assertSame(0.0, $similarity);
    }

    public function testJaccardSimilarityWithBothEmptySets(): void
    {
        $similarity = $this->calculator->jaccardSimilarity([], []);

        $this->assertSame(0.0, $similarity);
    }

    public function testJaccardSimilarityWithSingleSharedGenre(): void
    {
        $similarity = $this->calculator->jaccardSimilarity(
            ['Rock'],
            ['Rock'],
        );

        $this->assertSame(1.0, $similarity);
    }

    public function testJaccardSimilarityHandlesDuplicateGenres(): void
    {
        // array_intersect preserves duplicates, array_unique deduplicates union
        // Intersection: ['Rock', 'Rock', 'Pop'] (count 3)
        // Union (unique): ['Rock', 'Pop'] (count 2)
        // Result: 3/2 = 1.5
        $similarity = $this->calculator->jaccardSimilarity(
            ['Rock', 'Rock', 'Pop'],
            ['Rock', 'Pop', 'Pop'],
        );

        $this->assertSame(1.5, $similarity);
    }

    public function testJaccardSimilarityValueIsBetweenZeroAndOne(): void
    {
        $similarity = $this->calculator->jaccardSimilarity(
            ['Rock', 'Pop', 'Jazz', 'Electronic'],
            ['Rock', 'Classical'],
        );

        $this->assertGreaterThanOrEqual(0.0, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    public function testWeightedSimilarityWithIdenticalSets(): void
    {
        $similarity = $this->calculator->weightedSimilarity(
            ['Rock', 'Pop'],
            ['Rock', 'Pop'],
        );

        $this->assertSame(1.0, $similarity);
    }

    public function testWeightedSimilarityWithDisjointSets(): void
    {
        $similarity = $this->calculator->weightedSimilarity(
            ['Rock'],
            ['Jazz'],
        );

        $this->assertSame(0.0, $similarity);
    }

    public function testWeightedSimilarityWithOneEmptySet(): void
    {
        $similarity = $this->calculator->weightedSimilarity(
            ['Rock'],
            [],
        );

        $this->assertSame(0.0, $similarity);
    }

    public function testWeightedSimilarityWithParentMap(): void
    {
        $parentMap = [
            'alternative-rock' => 'rock',
            'indie-pop' => 'pop',
        ];

        // 'rock' is a parent of 'alternative-rock'
        // So 'alternative-rock' expands to: ['alternative-rock' => 1.0, 'rock' => 0.5]
        // 'rock' stays as: ['rock' => 1.0]
        $similarity = $this->calculator->weightedSimilarity(
            ['alternative-rock'],
            ['rock'],
            $parentMap,
        );

        $this->assertGreaterThan(0.0, $similarity);
    }

    public function testWeightedSimilarityWithDeepHierarchy(): void
    {
        $parentMap = [
            'math-rock' => 'post-rock',
            'post-rock' => 'rock',
            'rock' => null,
        ];

        // math-rock -> post-rock (weight 0.5) -> rock (weight 0.25)
        $similarity = $this->calculator->weightedSimilarity(
            ['math-rock'],
            ['rock'],
            $parentMap,
        );

        // Both should match because math-rock's ancestors include rock
        $this->assertGreaterThan(0.0, $similarity);
    }

    public function testWeightedSimilarityHierarchyDepthLimitedToThree(): void
    {
        $parentMap = [
            'level-1' => 'level-2',
            'level-2' => 'level-3',
            'level-3' => 'level-4',
            'level-4' => 'level-5',
        ];

        // level-1 -> level-2 (0.5) -> level-3 (0.25) -> stops at depth 3
        // level-4 is not included because depth limit is 3
        $similarity = $this->calculator->weightedSimilarity(
            ['level-1'],
            ['level-3'],
            $parentMap,
        );

        $this->assertGreaterThan(0.0, $similarity);
    }

    public function testWeightedSimilarityDeepHierarchyExcludesBeyondDepthThree(): void
    {
        $parentMap = [
            'sub-sub-sub-genre' => 'sub-sub-genre',
            'sub-sub-genre' => 'sub-genre',
            'sub-genre' => 'parent-genre',
            'parent-genre' => 'root-genre',
        ];

        // sub-sub-sub-genre expands to:
        //   sub-sub-sub-genre: 1.0
        //   sub-sub-genre: 0.5 (depth 1)
        //   sub-genre: 0.25 (depth 2)
        //   parent-genre: 0.1667 (depth 3)
        //   root-genre: NOT included (depth 4, exceeds limit of 3)

        // root-genre as a standalone expands to: root-genre: 1.0
        // So sub-sub-sub-genre and root-genre have no overlap
        $similarity = $this->calculator->weightedSimilarity(
            ['sub-sub-sub-genre'],
            ['root-genre'],
            $parentMap,
        );

        $this->assertSame(0.0, $similarity);
    }

    public function testWeightedSimilarityMultiLevelParentHierarchyWeightDecay(): void
    {
        $parentMap = [
            'math-rock' => 'post-rock',
            'post-rock' => 'progressive-rock',
            'progressive-rock' => 'rock',
        ];

        // math-rock expands to:
        //   math-rock: 1.0
        //   post-rock: 0.5
        //   progressive-rock: 0.25
        //   rock: 0.1667
        //
        // rock expands to:
        //   rock: 1.0
        //
        // Overlap: rock (weight 0.1667 vs 1.0 => avg = 0.5833)
        // aExpanded count = 4 (math-rock, post-rock, progressive-rock, rock)
        // bExpanded count = 1 (rock)
        // union count = 4 (math-rock, post-rock, progressive-rock, rock)
        // similarity = 0.5833 / 4 = 0.1458
        $similarity = $this->calculator->weightedSimilarity(
            ['math-rock'],
            ['rock'],
            $parentMap,
        );

        $this->assertGreaterThan(0.0, $similarity);
        $this->assertLessThan(0.3, $similarity);
    }

    public function testWeightedSimilarityMultiLevelBothSidesExpand(): void
    {
        $parentMap = [
            'death-metal' => 'metal',
            'thrash-metal' => 'metal',
            'metal' => 'rock',
            'punk' => 'rock',
        ];

        // death-metal expands to: death-metal(1.0), metal(0.5), rock(0.25)
        // punk expands to: punk(1.0), rock(0.5)
        //
        // Overlap: rock (0.25 + 0.5)/2 = 0.375
        // aCount = 3, bCount = 2, union = 4 (death-metal, metal, rock, punk)
        // similarity = 0.375 / 4 = 0.09375
        $similarity = $this->calculator->weightedSimilarity(
            ['death-metal'],
            ['punk'],
            $parentMap,
        );

        $this->assertGreaterThan(0.0, $similarity);
        $this->assertLessThan(0.2, $similarity);
    }

    public function testWeightedSimilarityHierarchyChainOfFiveTruncatesAtDepthThree(): void
    {
        $parentMap = [
            'lvl-a' => 'lvl-b',
            'lvl-b' => 'lvl-c',
            'lvl-c' => 'lvl-d',
            'lvl-d' => 'lvl-e',
            'lvl-e' => 'lvl-f',
        ];

        // lvl-a expands to: lvl-a(1.0), lvl-b(0.5), lvl-c(0.25), lvl-d(0.1667)
        // lvl-e and lvl-f are NOT expanded (beyond depth 3)
        // lvl-e as standalone: lvl-e(1.0)
        // No overlap between lvl-a expansion and lvl-e
        $similarity = $this->calculator->weightedSimilarity(
            ['lvl-a'],
            ['lvl-e'],
            $parentMap,
        );

        $this->assertSame(0.0, $similarity);
    }

    public function testWeightedSimilarityWithComplexParentMap(): void
    {
        $parentMap = [
            'progressive-metal' => 'progressive-rock',
            'progressive-rock' => 'rock',
            'djent' => 'progressive-metal',
            'post-punk' => 'punk',
            'punk' => 'rock',
        ];

        // djent -> progressive-metal (0.5) -> progressive-rock (0.25) -> rock (0.167)
        // punk -> rock (0.5)
        // So djent and punk both expand to include rock, giving partial similarity
        $similarity = $this->calculator->weightedSimilarity(
            ['djent'],
            ['punk'],
            $parentMap,
        );

        $this->assertGreaterThan(0.0, $similarity);
        $this->assertLessThan(1.0, $similarity);
    }

    public function testWeightedSimilarityWithoutParentMapBehavesLikeJaccard(): void
    {
        $genres = ['Rock', 'Pop', 'Jazz'];

        $similarity = $this->calculator->weightedSimilarity(
            $genres,
            $genres,
        );

        $this->assertSame(1.0, $similarity);
    }

    public function testWeightedSimilarityNullParentDoesNotExpand(): void
    {
        $parentMap = [
            'rock' => null,
        ];

        $similarity = $this->calculator->weightedSimilarity(
            ['rock'],
            ['rock'],
            $parentMap,
        );

        $this->assertSame(1.0, $similarity);
    }

    public function testWeightedSimilarityWithSharedParentGivesPartialScore(): void
    {
        $parentMap = [
            'death-metal' => 'metal',
            'thrash-metal' => 'metal',
        ];

        // Both genres share the parent 'metal'
        // Each expands to include 'metal' with weight 0.5
        $similarity = $this->calculator->weightedSimilarity(
            ['death-metal'],
            ['thrash-metal'],
            $parentMap,
        );

        $this->assertGreaterThan(0.0, $similarity);
        $this->assertLessThan(1.0, $similarity);
    }
}
