<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Domain\Model;

use App\Metadata\Domain\Model\MatchQuality;
use PHPUnit\Framework\TestCase;

final class MatchQualityTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.9,
            albumScore: 0.8,
            songScore: 1.0,
            overallScore: 0.9,
            reasons: ['Exact artist match', 'Similar album name'],
        );

        $this->assertSame(0.9, $quality->getArtistScore());
        $this->assertSame(0.8, $quality->getAlbumScore());
        $this->assertSame(1.0, $quality->getSongScore());
        $this->assertSame(0.9, $quality->getOverallScore());
        $this->assertSame(['Exact artist match', 'Similar album name'], $quality->getReasons());
    }

    public function testConstructionWithEmptyReasons(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.5,
            albumScore: 0.5,
            songScore: 0.5,
            overallScore: 0.5,
            reasons: [],
        );

        $this->assertSame([], $quality->getReasons());
    }

    public function testScoresAreClampedToMaximum(): void
    {
        $quality = new MatchQuality(
            artistScore: 1.5,
            albumScore: 2.0,
            songScore: 10.0,
            overallScore: 5.0,
            reasons: [],
        );

        $this->assertSame(1.0, $quality->getArtistScore());
        $this->assertSame(1.0, $quality->getAlbumScore());
        $this->assertSame(1.0, $quality->getSongScore());
        $this->assertSame(1.0, $quality->getOverallScore());
    }

    public function testScoresAreClampedToMinimum(): void
    {
        $quality = new MatchQuality(
            artistScore: -0.5,
            albumScore: -1.0,
            songScore: -2.0,
            overallScore: -0.3,
            reasons: [],
        );

        $this->assertSame(0.0, $quality->getArtistScore());
        $this->assertSame(0.0, $quality->getAlbumScore());
        $this->assertSame(0.0, $quality->getSongScore());
        $this->assertSame(0.0, $quality->getOverallScore());
    }

    public function testScoresAtBoundaryValues(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.0,
            albumScore: 1.0,
            songScore: 0.5,
            overallScore: 0.0,
            reasons: [],
        );

        $this->assertSame(0.0, $quality->getArtistScore());
        $this->assertSame(1.0, $quality->getAlbumScore());
        $this->assertSame(0.5, $quality->getSongScore());
        $this->assertSame(0.0, $quality->getOverallScore());
    }

    public function testIsAcceptableWithDefaultThreshold(): void
    {
        $goodQuality = new MatchQuality(
            artistScore: 0.9,
            albumScore: 0.8,
            songScore: 0.9,
            overallScore: 0.8,
            reasons: [],
        );

        $this->assertTrue($goodQuality->isAcceptable());
    }

    public function testIsAcceptableBelowDefaultThreshold(): void
    {
        $poorQuality = new MatchQuality(
            artistScore: 0.3,
            albumScore: 0.2,
            songScore: 0.4,
            overallScore: 0.4,
            reasons: [],
        );

        $this->assertFalse($poorQuality->isAcceptable());
    }

    public function testIsAcceptableExactlyAtDefaultThreshold(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.6,
            albumScore: 0.6,
            songScore: 0.6,
            overallScore: 0.6,
            reasons: [],
        );

        $this->assertTrue($quality->isAcceptable());
    }

    public function testIsAcceptableWithCustomThreshold(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.5,
            albumScore: 0.5,
            songScore: 0.5,
            overallScore: 0.5,
            reasons: [],
        );

        $this->assertTrue($quality->isAcceptable(0.5));
        $this->assertFalse($quality->isAcceptable(0.6));
    }

    public function testIsAcceptableWithHighThreshold(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.9,
            albumScore: 0.9,
            songScore: 0.9,
            overallScore: 0.9,
            reasons: [],
        );

        $this->assertTrue($quality->isAcceptable(0.9));
        $this->assertFalse($quality->isAcceptable(0.95));
    }

    public function testIsAcceptableWithZeroThreshold(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.0,
            albumScore: 0.0,
            songScore: 0.0,
            overallScore: 0.0,
            reasons: [],
        );

        $this->assertTrue($quality->isAcceptable(0.0));
    }

    public function testGetReasonsReturnsArray(): void
    {
        $quality = new MatchQuality(
            artistScore: 0.7,
            albumScore: 0.8,
            songScore: 0.6,
            overallScore: 0.7,
            reasons: ['Artist name matches', 'Album title similar', 'High Levenshtein similarity'],
        );

        $reasons = $quality->getReasons();
        $this->assertIsArray($reasons);
        $this->assertCount(3, $reasons);
        $this->assertSame('Artist name matches', $reasons[0]);
        $this->assertSame('Album title similar', $reasons[1]);
        $this->assertSame('High Levenshtein similarity', $reasons[2]);
    }
}
