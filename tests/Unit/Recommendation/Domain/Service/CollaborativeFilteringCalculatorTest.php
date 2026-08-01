<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Domain\Service;

use App\Recommendation\Domain\Service\CollaborativeFilteringCalculator;
use PHPUnit\Framework\TestCase;

final class CollaborativeFilteringCalculatorTest extends TestCase
{
    private CollaborativeFilteringCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CollaborativeFilteringCalculator();
    }

    // ---------------------------------------------------------------------------
    // userSimilarity
    // ---------------------------------------------------------------------------

    public function testUserSimilarityWithIdenticalVectorsReturnsOne(): void
    {
        $a = ['item-1' => 5, 'item-2' => 3, 'item-3' => 4];
        $b = ['item-1' => 5, 'item-2' => 3, 'item-3' => 4];

        $similarity = $this->calculator->userSimilarity($a, $b);

        $this->assertEqualsWithDelta(1.0, $similarity, 0.001);
    }

    public function testUserSimilarityWithLessThanTwoCommonItemsReturnsZero(): void
    {
        $a = ['item-1' => 5, 'item-2' => 3, 'item-3' => 4];
        $b = ['item-1' => 2, 'item-99' => 7];

        // Only 'item-1' in common
        $similarity = $this->calculator->userSimilarity($a, $b);

        $this->assertSame(0.0, $similarity);
    }

    public function testUserSimilarityWithZeroDenominatorReturnsZero(): void
    {
        // Both users rate all common items with the same value => diffA and diffB are 0
        $a = ['item-1' => 5, 'item-2' => 5, 'item-3' => 5];
        $b = ['item-1' => 3, 'item-2' => 3, 'item-3' => 3];

        // meanA = 5, meanB = 3
        // diffA = 5-5 = 0 for all items => denomA = 0 => denominator = 0
        $similarity = $this->calculator->userSimilarity($a, $b);

        $this->assertSame(0.0, $similarity);
    }

    public function testUserSimilarityWithKnownPearsonValues(): void
    {
        // Hand-computed Pearson correlation
        // User A: {x: 1, y: 2, z: 3}  meanA = 2
        // User B: {x: 1, y: 5, z: 6}  meanB = 4
        // diffA: {-1, 0, 1}  diffB: {-3, 1, 2}
        // num = (-1)(-3) + (0)(1) + (1)(2) = 3 + 0 + 2 = 5
        // denomA = 1 + 0 + 1 = 2 => sqrt(2)
        // denomB = 9 + 1 + 4 = 14 => sqrt(14)
        // Pearson = 5 / (sqrt(2) * sqrt(14)) = 5 / sqrt(28) = 5 / 5.2915 = 0.9449
        $a = ['x' => 1, 'y' => 2, 'z' => 3];
        $b = ['x' => 1, 'y' => 5, 'z' => 6];

        $similarity = $this->calculator->userSimilarity($a, $b);

        $this->assertEqualsWithDelta(0.945, $similarity, 0.001);
    }

    public function testUserSimilarityWithNegativeCorrelation(): void
    {
        // User A: {x: 1, y: 2, z: 3}  meanA = 2
        // User B: {x: 3, y: 2, z: 1}  meanB = 2
        // diffA: {-1, 0, 1}  diffB: {1, 0, -1}
        // num = (-1)(1) + (0)(0) + (1)(-1) = -1 + 0 - 1 = -2
        // denomA = 1 + 0 + 1 = 2 => sqrt(2)
        // denomB = 1 + 0 + 1 = 2 => sqrt(2)
        // Pearson = -2 / (sqrt(2) * sqrt(2)) = -2 / 2 = -1.0
        $a = ['x' => 1, 'y' => 2, 'z' => 3];
        $b = ['x' => 3, 'y' => 2, 'z' => 1];

        $similarity = $this->calculator->userSimilarity($a, $b);

        $this->assertEqualsWithDelta(-1.0, $similarity, 0.001);
    }

    // ---------------------------------------------------------------------------
    // recommend
    // ---------------------------------------------------------------------------

    public function testRecommendWithEmptyOtherUsersReturnsEmptyArray(): void
    {
        $recommendations = $this->calculator->recommend(
            ['item-1' => 5, 'item-2' => 3],
            [],
        );

        $this->assertSame([], $recommendations);
    }

    public function testRecommendWithNoPositiveSimilaritiesReturnsEmptyArray(): void
    {
        // Other users have no common items with target => similarity = 0.0
        $recommendations = $this->calculator->recommend(
            ['item-1' => 5, 'item-2' => 3, 'item-3' => 4],
            [
                'user-1' => ['item-99' => 5, 'item-98' => 3],
                'user-2' => ['item-97' => 2, 'item-96' => 4],
            ],
        );

        $this->assertSame([], $recommendations);
    }

    public function testRecommendReturnsItemsSortedDescending(): void
    {
        $targetItems = ['item-1' => 5, 'item-2' => 3];
        $otherUsers = [
            'user-1' => [
                'item-1' => 5, 'item-2' => 3, 'item-3' => 4, 'item-4' => 1,
            ],
        ];

        $recommendations = $this->calculator->recommend($targetItems, $otherUsers, 10);

        $this->assertCount(2, $recommendations);
        $this->assertGreaterThanOrEqual($recommendations[1]['score'], $recommendations[0]['score']);
    }

    public function testRecommendRespectsLimit(): void
    {
        $targetItems = ['item-1' => 5, 'item-2' => 3, 'item-3' => 4, 'item-4' => 2];
        $otherUsers = [
            'user-1' => [
                'item-1' => 5, 'item-2' => 3, 'item-3' => 4, 'item-4' => 2,
                'item-5' => 5, 'item-6' => 1, 'item-7' => 3, 'item-8' => 4,
            ],
        ];

        $recommendations = $this->calculator->recommend($targetItems, $otherUsers, 2);

        $this->assertCount(2, $recommendations);
    }

    public function testRecommendClampsNegativeScoresToZero(): void
    {
        // Target user: {item-1: 5, item-2: 5} meanA = 5
        // Other user: {item-1: 1, item-2: 1, item-3: 1} meanB = 1
        // Common items: item-1, item-2 => userSimilarity computed
        // diffA for common items: (5-5)=0, (5-5)=0 => denomA=0 => similarity=0.0
        // This won't produce positive similarity. Let me try a different approach.

        // Target user: {a: 1, b: 2, c: 3} mean = 2
        // Other user: {a: 3, b: 2, c: 1, d: 1} mean = 1.75
        // Common items: a, b, c (count=3 >= 2, OK)
        // diffA: a(-1), b(0), c(1)  diffB: a(1.25), b(0.25), c(-0.75)
        // num = (-1)(1.25) + (0)(0.25) + (1)(-0.75) = -1.25 + 0 - 0.75 = -2.0
        // denomA = 1 + 0 + 1 = 2  denomB = 1.5625 + 0.0625 + 0.5625 = 2.1875
        // sim = -2.0 / (sqrt(2) * sqrt(2.1875)) = -2.0 / (1.414 * 1.479) = -2.0 / 2.092 = -0.956
        // similarity < 0 => filtered out by "sim > 0.0" check. No recommendations.
        // That won't produce the clamping either. We need a user with positive similarity
        // but where the deviation produces a negative prediction.

        // Target user: {a: 5, b: 5} mean = 5
        // Other user: {a: 1, b: 1, c: 1, d: 1} mean = 1
        // Common: a, b. diffA: (5-5)=0, (5-5)=0 => similarity = 0. Not useful.

        // To get positive similarity AND negative prediction:
        // Target: {a: 4, b: 6} mean = 5
        // Other: {a: 2, b: 8, c: 1, d: 1} mean = 3
        // Common: a, b (count=2 OK)
        // diffA: a(-1), b(1)  diffB: a(-1), b(5)
        // num = (-1)(-1) + (1)(5) = 1 + 5 = 6
        // denomA = 1 + 1 = 2  denomB = 1 + 25 = 26
        // sim = 6 / (sqrt(2)*sqrt(26)) = 6 / 7.211 = 0.832
        // Prediction for c: 5 + 0.832*(1-3) / 0.832 = 5 + (1-3) = 5 + (-2) = 3.0 > 0
        // Still positive. Let me make the deviation very negative.

        // Target: {a: 5, b: 5} mean = 5
        // Other: {a: 1, b: 9, c: 1} mean = 11/3 = 3.667
        // Common: a, b (count=2 OK)
        // diffA: a(0), b(0) => denomA = 0 => similarity = 0. Not useful.

        // Target: {a: 3, b: 7} mean = 5
        // Other: {a: 1, b: 9, c: 1, d: 1} mean = 3
        // Common: a, b (count=2 OK)
        // diffA: a(-2), b(2)  diffB: a(-2), b(6)
        // num = (-2)(-2) + (2)(6) = 4 + 12 = 16
        // denomA = 4 + 4 = 8  denomB = 4 + 36 = 40
        // sim = 16 / (sqrt(8)*sqrt(40)) = 16 / 17.889 = 0.894
        // Prediction for c: 5 + 0.894*(1-3) / 0.894 = 5 + (1-3) = 3.0. Still positive.

        // The formula clamps: max(0.0, targetMean + numerator/denominator)
        // numerator = sum(sim * (rating - userMean))
        // If sim > 0 and (rating - userMean) is very negative, the sum is negative enough
        // to push targetMean + (...) below 0.

        // Let's try: Target has very LOW mean, other user rates candidate item very low
        // Target: {a: 1, b: 1} mean = 1
        // Other: {a: 1, b: 1, c: 1} mean = 1
        // diffA: a(0), b(0) => similarity = 0. Not useful.

        // The real scenario: need rating << userMean for the other user.
        // Target: {a: 2, b: 4} mean = 3
        // Other: {a: 1, b: 5, c: 1} mean = 7/3 = 2.333
        // Common: a, b (count=2 OK)
        // diffA: a(-1), b(1)  diffB: a(-1.333), b(2.667)
        // num = (-1)(-1.333) + (1)(2.667) = 1.333 + 2.667 = 4.0
        // denomA = 1+1=2  denomB = 1.778+7.113=8.891
        // sim = 4 / (sqrt(2)*sqrt(8.891)) = 4/4.216 = 0.949
        // Prediction for c: 3 + 0.949*(1-2.333)/0.949 = 3 + (1-2.333) = 3 - 1.333 = 1.667 > 0

        // To get a truly negative prediction: we need multiple similar users that
        // all rate the candidate well below their mean, and the target mean is low.
        // Actually, for a single-user scenario the prediction is always targetMean + (rating - userMean).
        // This is targetMean - userMean + rating. If targetMean < userMean, this can go negative
        // when rating is also low.

        // Target: {a: 1, b: 1} mean = 1
        // Other: {a: 5, b: 5, c: 1} mean = 11/3 = 3.667
        // Common: a, b (count=2 OK)
        // diffA: a(-1), b(-1) => all same diff => denomA = 2
        // diffB: a(1.333), b(1.333) => denomB = 2*1.778 = 3.556
        // num = (-1)(1.333) + (-1)(1.333) = -2.666
        // sim = -2.666 / (sqrt(2)*sqrt(3.556)) = -2.666 / 2.667 = -0.9996
        // Negative similarity => filtered out!

        // With TWO other users where one is positive similarity:
        $targetItems = ['a' => 1, 'b' => 1]; // mean = 1
        $otherUsers = [
            // User 1: positive similarity, candidate c rated well above their mean
            'user-1' => ['a' => 2, 'b' => 2, 'c' => 1], // mean = 5/3, c-mean = -2/3
            // User 2: also positive similarity, candidate c rated well below their mean
            'user-2' => ['a' => 1, 'b' => 1, 'c' => 10], // mean = 4, c-mean = 6
        ];

        // For user-1 and target: common a,b.
        // diffA: (-1/3, -1/3), diffB: (1/3, 1/3). num = -2/9, denom = 2/3 * 2/3 => sim < 0

        // Let me use a direct approach: construct data where the weighted deviation is strongly negative
        // and target mean is very low.

        $targetItems = ['x' => 1, 'y' => 1]; // mean = 1
        $otherUsers = [
            'user-1' => ['x' => 5, 'y' => 5, 'z' => 0], // mean = 10/3 = 3.333
        ];

        // diffA: x(-1), y(-1). diffB: x(1.667), y(1.667)
        // num = (-1)(1.667) + (-1)(1.667) = -3.333
        // denomA = 1+1 = 2. denomB = 2.778 + 2.778 = 5.556
        // sim = -3.333 / (sqrt(2)*sqrt(5.556)) = -3.333 / 3.333 = -1.0
        // Negative => filtered. No good.

        // The problem is: when users have opposite patterns to target, similarity is negative
        // and they get filtered. When they have similar patterns, their deviation (rating - mean)
        // won't push the prediction below 0 unless the candidate is rated much lower than
        // the user's mean AND target mean is also low.

        // With the clamp in place, let me just verify that a score of exactly 0 is valid:
        // This test verifies the max(0.0, ...) clamp works by checking that scores are never negative.
        // Since it's hard to construct data that produces exactly a negative raw score while
        // still having positive similarity, let me test the clamping boundary directly.

        // Target: {a: 1, b: 3} mean = 2
        // Other: {a: 1, b: 3, c: 1, d: 1} mean = 1.5
        // diffA: a(-1), b(1)  diffB: a(-0.5), b(1.5)
        // num = 0.5 + 1.5 = 2.0
        // denomA = 1+1=2  denomB = 0.25+2.25=2.5
        // sim = 2/(sqrt(2)*sqrt(2.5)) = 2/2.236 = 0.894
        // Prediction for c: 2 + 0.894*(1-1.5)/0.894 = 2 + (-0.5) = 1.5 > 0
        // Prediction for d: same = 1.5 > 0

        // This is inherently hard to make negative with single user because:
        // prediction = targetMean + (rating - userMean)
        // For this to be < 0: rating < userMean - targetMean.
        // If targetMean=2, userMean=1.5: need rating < -0.5. Not possible with positive ratings.

        // But with PLAY COUNTS (integers >= 0): need rating < userMean - targetMean
        // If userMean is large relative to targetMean and rating is 0:
        // Target mean = 2, user mean = 5, rating = 0: 2 + (0-5) = -3 < 0!
        // But then the similarity pattern must be positive...

        // Target: {a: 2, b: 2} mean = 2
        // Other: {a: 4, b: 6, c: 0} mean = 10/3 = 3.333
        // diffA: a(0), b(0) => denomA = 0 => similarity = 0. No!

        // Target: {a: 1, b: 3} mean = 2
        // Other: {a: 2, b: 4, c: 0, d: 0} mean = 1.5
        // diffA: a(-1), b(1)  diffB: a(0.5), b(2.5)
        // num = -0.5 + 2.5 = 2.0
        // denomA = 1+1=2  denomB = 0.25+6.25=6.5
        // sim = 2/(sqrt(2)*sqrt(6.5)) = 2/3.606 = 0.555
        // Prediction for c: 2 + 0.555*(0-1.5)/0.555 = 2 + (-1.5) = 0.5 > 0

        // Target: {a: 1, b: 2} mean = 1.5
        // Other: {a: 3, b: 6, c: 0} mean = 3
        // diffA: a(-0.5), b(0.5)  diffB: a(0), b(3)
        // num = 0 + 1.5 = 1.5
        // denomA = 0.25+0.25=0.5  denomB = 0+9=9
        // sim = 1.5/(sqrt(0.5)*sqrt(9)) = 1.5/2.121 = 0.707
        // Prediction for c: 1.5 + 0.707*(0-3)/0.707 = 1.5 + (-3) = -1.5 => clamped to 0.0!
        $targetItems = ['a' => 1, 'b' => 2];
        $otherUsers = [
            'user-1' => ['a' => 3, 'b' => 6, 'c' => 0],
        ];

        $recommendations = $this->calculator->recommend($targetItems, $otherUsers, 10);

        $this->assertNotEmpty($recommendations);
        // The raw score would be -1.5, clamped to 0.0
        $this->assertSame(0.0, $recommendations[0]['score']);
    }

    public function testRecommendExcludesItemsTargetUserAlreadyHas(): void
    {
        $targetItems = ['item-1' => 5, 'item-2' => 3];
        $otherUsers = [
            'user-1' => [
                'item-1' => 5, 'item-2' => 3, 'item-3' => 4,
            ],
        ];

        $recommendations = $this->calculator->recommend($targetItems, $otherUsers, 10);

        foreach ($recommendations as $rec) {
            $this->assertArrayNotHasKey($rec['id'], $targetItems);
        }
    }

    public function testRecommendWithTargetUserHavingEmptyHistory(): void
    {
        // Target user has no history => mean = 0.0, no common items => no similarity
        $recommendations = $this->calculator->recommend(
            [],
            ['user-1' => ['item-1' => 5, 'item-2' => 3, 'item-3' => 4]],
        );

        // No common items means no positive similarity
        $this->assertSame([], $recommendations);
    }

    // ---------------------------------------------------------------------------
    // coOccurrence
    // ---------------------------------------------------------------------------

    public function testCoOccurrenceWithNoUsersHavingItemReturnsEmptyArray(): void
    {
        $results = $this->calculator->coOccurrence(
            'item-999',
            [
                'user-1' => ['item-1' => 5, 'item-2' => 3],
                'user-2' => ['item-3' => 4, 'item-4' => 2],
            ],
        );

        $this->assertSame([], $results);
    }

    public function testCoOccurrenceReturnsItemsSortedDescending(): void
    {
        $results = $this->calculator->coOccurrence(
            'item-1',
            [
                'user-1' => ['item-1' => 5, 'item-2' => 10, 'item-3' => 2],
                'user-2' => ['item-1' => 3, 'item-2' => 5, 'item-3' => 8],
            ],
        );

        $this->assertNotEmpty($results);
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i]['score'], $results[$i - 1]['score']);
        }
    }

    public function testCoOccurrenceScoresAreProportional(): void
    {
        $results = $this->calculator->coOccurrence(
            'item-1',
            [
                'user-1' => ['item-1' => 5, 'item-2' => 3, 'item-3' => 7],
            ],
        );

        // Total play count of co-occurring items: 3 + 7 = 10
        // item-2 score = 3/10 = 0.3, item-3 score = 7/10 = 0.7
        $this->assertCount(2, $results);
        $this->assertEqualsWithDelta(0.7, $results[0]['score'], 0.001);
        $this->assertSame('item-3', $results[0]['id']);
        $this->assertEqualsWithDelta(0.3, $results[1]['score'], 0.001);
        $this->assertSame('item-2', $results[1]['id']);
    }

    public function testCoOccurrenceRespectsLimit(): void
    {
        $results = $this->calculator->coOccurrence(
            'item-1',
            [
                'user-1' => [
                    'item-1' => 5, 'item-2' => 3, 'item-3' => 7,
                    'item-4' => 1, 'item-5' => 2,
                ],
            ],
            2,
        );

        $this->assertCount(2, $results);
    }
}
