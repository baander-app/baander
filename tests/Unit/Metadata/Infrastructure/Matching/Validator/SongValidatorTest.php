<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Matching\Validator;

use App\Metadata\Infrastructure\Matching\Validator\SongValidator;
use PHPUnit\Framework\TestCase;

final class SongValidatorTest extends TestCase
{
    private SongValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SongValidator();
    }

    public function testExactMatchReturnsOne(): void
    {
        $score = $this->validator->validate('Bohemian Rhapsody', 'Bohemian Rhapsody');

        $this->assertSame(1.0, $score);
    }

    public function testCaseInsensitiveExactMatchReturns095(): void
    {
        $score = $this->validator->validate('Bohemian Rhapsody', 'bohemian rhapsody');

        $this->assertSame(0.95, $score);
    }

    public function testMatchWithFeaturedArtistInParentheses(): void
    {
        $score = $this->validator->validate(
            'Single Ladies (feat. Jay-Z)',
            'Single Ladies',
        );

        $this->assertSame(0.85, $score);
    }

    public function testMatchWithFeaturedArtistInBrackets(): void
    {
        $score = $this->validator->validate(
            'Single Ladies [feat. Jay-Z]',
            'Single Ladies',
        );

        $this->assertSame(0.85, $score);
    }

    public function testMatchWithFtNotation(): void
    {
        $score = $this->validator->validate(
            'Single Ladies ft. Jay-Z',
            'Single Ladies',
        );

        $this->assertSame(0.85, $score);
    }

    public function testMatchWithFeaturingNotation(): void
    {
        $score = $this->validator->validate(
            'Single Ladies featuring Jay-Z',
            'Single Ladies',
        );

        $this->assertSame(0.85, $score);
    }

    public function testBothTitlesHaveFeaturedArtistStripped(): void
    {
        $score = $this->validator->validate(
            'Crazy in Love (feat. Jay-Z)',
            'Crazy in Love ft. Jay-Z',
        );

        // Raw strings differ, but after stripping features both match => 0.85
        $this->assertSame(0.85, $score);
    }

    public function testSimilarTitlesReturnLevenshteinScore(): void
    {
        $score = $this->validator->validate('Smells Like Teen Spirit', 'Smells Like Teen Spirt');

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(0.85, $score);
    }

    public function testCompletelyDifferentTitlesReturnZero(): void
    {
        $score = $this->validator->validate('Bohemian Rhapsody', 'Stairway to Heaven');

        $this->assertSame(0.0, $score);
    }

    public function testEmptyStringsReturnZero(): void
    {
        $score = $this->validator->validate('', 'Some Song Title');

        $this->assertSame(0.0, $score);
    }

    public function testBothEmptyStringsReturnPerfectMatch(): void
    {
        $score = $this->validator->validate('', '');

        // Empty strings are exactly equal, so score is 1.0
        $this->assertSame(1.0, $score);
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $score = $this->validator->validate('  Test Song  ', 'Test Song');

        $this->assertSame(1.0, $score);
    }

    public function testContainsMatchReturns07(): void
    {
        $score = $this->validator->validate('Short Title', 'This is a Short Title Extended');

        $this->assertSame(0.7, $score);
    }

    public function testScoreIsBoundedBetweenZeroAndOne(): void
    {
        $score = $this->validator->validate('Test', 'Test');

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testNearMatchReturnsHighScore(): void
    {
        // One character difference
        $score = $this->validator->validate('Yesterday', 'Yesterdays');

        $this->assertGreaterThan(0.7, $score);
        $this->assertLessThan(1.0, $score);
    }
}
