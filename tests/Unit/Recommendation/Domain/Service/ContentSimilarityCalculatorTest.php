<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Domain\Service;

use App\Recommendation\Domain\Service\ContentSimilarityCalculator;
use PHPUnit\Framework\TestCase;

final class ContentSimilarityCalculatorTest extends TestCase
{
    private ContentSimilarityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ContentSimilarityCalculator();
    }

    public function testCosineSimilarityWithIdenticalVectors(): void
    {
        $a = ['energy' => 0.8, 'danceability' => 0.6, 'valence' => 0.7];
        $b = ['energy' => 0.8, 'danceability' => 0.6, 'valence' => 0.7];

        $similarity = $this->calculator->cosineSimilarity($a, $b);

        $this->assertEqualsWithDelta(1.0, $similarity, 0.001);
    }

    public function testCosineSimilarityWithOrthogonalVectors(): void
    {
        // These vectors have no overlapping keys
        $a = ['energy' => 0.8, 'danceability' => 0.6];
        $b = ['valence' => 0.7, 'acousticness' => 0.5];

        $similarity = $this->calculator->cosineSimilarity($a, $b);

        $this->assertSame(0.0, $similarity);
    }

    public function testCosineSimilarityWithDisjointKeysReturnsZero(): void
    {
        $a = ['a' => 1.0, 'b' => 2.0];
        $b = ['c' => 3.0, 'd' => 4.0];

        $similarity = $this->calculator->cosineSimilarity($a, $b);

        $this->assertSame(0.0, $similarity);
    }

    public function testCosineSimilarityWithEmptyVectors(): void
    {
        $similarity = $this->calculator->cosineSimilarity([], []);

        $this->assertSame(0.0, $similarity);
    }

    public function testCosineSimilarityWithZeroVector(): void
    {
        $a = ['energy' => 0.0, 'danceability' => 0.0];
        $b = ['energy' => 0.8, 'danceability' => 0.6];

        $similarity = $this->calculator->cosineSimilarity($a, $b);

        $this->assertSame(0.0, $similarity);
    }

    public function testCosineSimilarityWithPartialOverlap(): void
    {
        $a = ['energy' => 0.8, 'danceability' => 0.6, 'valence' => 0.7];
        $b = ['energy' => 0.8, 'danceability' => 0.6, 'acousticness' => 0.5];

        // Only 'energy' and 'danceability' overlap
        // Dot product = 0.8*0.8 + 0.6*0.6 = 1.0
        // normA = sqrt(0.8^2 + 0.6^2) = 1.0
        // normB = sqrt(0.8^2 + 0.6^2) = 1.0
        // cosine = 1.0 / (1.0 * 1.0) = 1.0
        $similarity = $this->calculator->cosineSimilarity($a, $b);

        $this->assertEqualsWithDelta(1.0, $similarity, 0.001);
    }

    public function testCosineSimilarityReturnsValueBetweenZeroAndOne(): void
    {
        $a = ['energy' => 0.5, 'danceability' => 0.3];
        $b = ['energy' => 0.7, 'danceability' => 0.9];

        $similarity = $this->calculator->cosineSimilarity($a, $b);

        $this->assertGreaterThanOrEqual(0.0, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    public function testEuclideanDistanceWithIdenticalVectors(): void
    {
        $a = ['energy' => 0.8, 'danceability' => 0.6];
        $b = ['energy' => 0.8, 'danceability' => 0.6];

        $distance = $this->calculator->euclideanDistance($a, $b);

        $this->assertEqualsWithDelta(0.0, $distance, 0.001);
    }

    public function testEuclideanDistanceWithDifferentVectors(): void
    {
        $a = ['energy' => 0.0, 'danceability' => 0.0];
        $b = ['energy' => 3.0, 'danceability' => 4.0];

        $distance = $this->calculator->euclideanDistance($a, $b);

        $this->assertEqualsWithDelta(5.0, $distance, 0.001);
    }

    public function testEuclideanDistanceWithDisjointKeysReturnsInfinity(): void
    {
        $a = ['energy' => 0.5];
        $b = ['valence' => 0.5];

        $distance = $this->calculator->euclideanDistance($a, $b);

        $this->assertSame(INF, $distance);
    }

    public function testEuclideanDistanceWithEmptyVectorsReturnsInfinity(): void
    {
        $distance = $this->calculator->euclideanDistance([], []);

        $this->assertSame(INF, $distance);
    }

    public function testEuclideanDistanceIsAlwaysPositive(): void
    {
        $a = ['energy' => 0.2, 'danceability' => 0.8];
        $b = ['energy' => 0.9, 'danceability' => 0.1];

        $distance = $this->calculator->euclideanDistance($a, $b);

        $this->assertGreaterThan(0.0, $distance);
    }

    public function testFindMostSimilarReturnsResultsSortedDescending(): void
    {
        $target = ['energy' => 0.5, 'danceability' => 0.5];

        $candidates = [
            ['id' => 'far', 'features' => ['energy' => 0.0, 'danceability' => 1.0]],
            ['id' => 'near', 'features' => ['energy' => 0.5, 'danceability' => 0.5]],
            ['id' => 'medium', 'features' => ['energy' => 0.5, 'danceability' => 0.4]],
        ];

        $results = $this->calculator->findMostSimilar($target, $candidates);

        $this->assertSame('near', $results[0]['id']);
        $this->assertGreaterThanOrEqual($results[1]['score'], $results[0]['score']);
    }

    public function testFindMostSimilarRespectsLimit(): void
    {
        $target = ['energy' => 0.5, 'danceability' => 0.5];

        $candidates = [
            ['id' => 'a', 'features' => ['energy' => 0.5, 'danceability' => 0.5]],
            ['id' => 'b', 'features' => ['energy' => 0.6, 'danceability' => 0.4]],
            ['id' => 'c', 'features' => ['energy' => 0.7, 'danceability' => 0.3]],
        ];

        $results = $this->calculator->findMostSimilar($target, $candidates, 2);

        $this->assertCount(2, $results);
    }

    public function testFindMostSimilarWithEmptyCandidates(): void
    {
        $results = $this->calculator->findMostSimilar(['energy' => 0.5], []);

        $this->assertSame([], $results);
    }

    public function testFindMostSimilarDefaultLimitIsTen(): void
    {
        $target = ['energy' => 0.5];

        $candidates = [];
        for ($i = 0; $i < 15; $i++) {
            $candidates[] = ['id' => "item-$i", 'features' => ['energy' => 0.5 * $i / 14]];
        }

        $results = $this->calculator->findMostSimilar($target, $candidates);

        $this->assertCount(10, $results);
    }
}
