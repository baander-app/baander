<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Domain\Model;

use App\Metadata\Domain\Model\MetadataMatch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MetadataMatchTest extends TestCase
{
    private array $candidate;

    protected function setUp(): void
    {
        $this->candidate = [
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
        ];
    }

    public function testConstructionWithValidValues(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.85,
            artistScore: 0.9,
            albumScore: 0.8,
            songScore: 0.85,
        );

        $this->assertSame($this->candidate, $match->getCandidate());
        $this->assertSame(0.85, $match->getConfidence());
        $this->assertSame(0.9, $match->getArtistScore());
        $this->assertSame(0.8, $match->getAlbumScore());
        $this->assertSame(0.85, $match->getSongScore());
    }

    public function testConstructionThrowsOnNegativeConfidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidence must be between 0.0 and 1.0');

        new MetadataMatch(
            candidate: $this->candidate,
            confidence: -0.1,
            artistScore: 0.5,
            albumScore: 0.5,
            songScore: 0.5,
        );
    }

    public function testConstructionThrowsOnConfidenceAboveOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidence must be between 0.0 and 1.0');

        new MetadataMatch(
            candidate: $this->candidate,
            confidence: 1.1,
            artistScore: 0.5,
            albumScore: 0.5,
            songScore: 0.5,
        );
    }

    public function testConstructionWithZeroConfidence(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.0,
            artistScore: 0.0,
            albumScore: 0.0,
            songScore: 0.0,
        );

        $this->assertSame(0.0, $match->getConfidence());
    }

    public function testConstructionWithFullConfidence(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 1.0,
            artistScore: 1.0,
            albumScore: 1.0,
            songScore: 1.0,
        );

        $this->assertSame(1.0, $match->getConfidence());
    }

    public function testIsHighConfidenceReturnsTrueAtThreshold(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.8,
            artistScore: 0.8,
            albumScore: 0.8,
            songScore: 0.8,
        );

        $this->assertTrue($match->isHighConfidence());
    }

    public function testIsHighConfidenceReturnsTrueAboveThreshold(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.95,
            artistScore: 0.9,
            albumScore: 0.9,
            songScore: 0.9,
        );

        $this->assertTrue($match->isHighConfidence());
    }

    public function testIsHighConfidenceReturnsFalseBelowThreshold(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.79,
            artistScore: 0.7,
            albumScore: 0.7,
            songScore: 0.7,
        );

        $this->assertFalse($match->isHighConfidence());
    }

    public function testIsMediumConfidenceReturnsTrueInRange(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.6,
            artistScore: 0.6,
            albumScore: 0.6,
            songScore: 0.6,
        );

        $this->assertTrue($match->isMediumConfidence());
    }

    public function testIsMediumConfidenceReturnsFalseAtLowerBound(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.49,
            artistScore: 0.4,
            albumScore: 0.4,
            songScore: 0.4,
        );

        $this->assertFalse($match->isMediumConfidence());
    }

    public function testIsMediumConfidenceReturnsFalseAtUpperBound(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.8,
            artistScore: 0.8,
            albumScore: 0.8,
            songScore: 0.8,
        );

        $this->assertFalse($match->isMediumConfidence());
    }

    public function testIsHighConfidenceIsNotMedium(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.9,
            artistScore: 0.9,
            albumScore: 0.9,
            songScore: 0.9,
        );

        $this->assertTrue($match->isHighConfidence());
        $this->assertFalse($match->isMediumConfidence());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $match = new MetadataMatch(
            candidate: $this->candidate,
            confidence: 0.75,
            artistScore: 0.8,
            albumScore: 0.7,
            songScore: 0.75,
        );

        $this->assertIsArray($match->getCandidate());
        $this->assertIsFloat($match->getConfidence());
        $this->assertIsFloat($match->getArtistScore());
        $this->assertIsFloat($match->getAlbumScore());
        $this->assertIsFloat($match->getSongScore());
    }
}
