<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Matching;

use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use App\Metadata\Infrastructure\Matching\Validator\AlbumValidator;
use App\Metadata\Infrastructure\Matching\Validator\ArtistValidator;
use App\Metadata\Infrastructure\Matching\Validator\SongValidator;
use PHPUnit\Framework\TestCase;

final class MatchingStrategyTest extends TestCase
{
    private MatchingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new MatchingStrategy(
            new ArtistValidator(),
            new AlbumValidator(),
            new SongValidator(),
        );
    }

    public function testMatchWithEmptyCandidatesReturnsEmptyArray(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Test Song',
            album: 'Test Album',
            artist: 'Test Artist',
        );

        $results = $this->strategy->match($extracted, []);

        $this->assertSame([], $results);
    }

    public function testMatchWithExactMatchCandidateReturnsHighConfidence(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Bohemian Rhapsody',
            album: 'A Night at the Opera',
            artist: 'Queen',
        );

        $candidates = [
            [
                'title' => 'Bohemian Rhapsody',
                'artist' => 'Queen',
                'album' => 'A Night at the Opera',
            ],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1.0, $results[0]->getConfidence(), 0.001);
    }

    public function testMatchFiltersOutVeryLowConfidenceCandidates(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Completely Unique Song Title',
            album: 'Completely Unique Album Title',
            artist: 'Completely Unique Artist Name',
        );

        // Candidate that shares no common substrings at all
        $candidates = [
            [
                'title' => 'XYZ',
                'artist' => 'ABC',
                'album' => 'DEF',
            ],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        // Short unrelated strings should not produce meaningful matches
        // (depends on Levenshtein scoring being below minimum threshold of 0.2)
        $this->assertSame([], $results);
    }

    public function testMatchResultsAreSortedByConfidenceDescending(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Test Song',
            album: 'Test Album',
            artist: 'Test Artist',
        );

        $candidates = [
            [
                'title' => 'Test Song',
                'artist' => 'Different Artist Name',
                'album' => 'Different Album Name',
            ],
            [
                'title' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual(
            $results[1]->confidence,
            $results[0]->confidence,
        );
    }

    public function testMatchHandlesCandidateWithMissingFields(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Test Song',
            album: 'Test Album',
            artist: 'Test Artist',
        );

        $candidates = [
            ['title' => 'Test Song'],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        // Song title matches perfectly, so songScore = 1.0
        // Confidence = 0*0.3 + 0*0.3 + 1.0*0.4 = 0.4 > 0.2 minimum
        $this->assertNotEmpty($results);
    }

    public function testMatchWithExtractedMetadataWithNullFields(): void
    {
        $extracted = new ExtractedMetadata();

        $candidates = [
            [
                'title' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ],
        ];

        // With null extracted values, validators receive empty strings
        // Empty string contains the candidate string (str_contains('', '...') is true)
        // so artist and album validators return 0.7 each
        // Confidence = 0.7*0.3 + 0.7*0.3 + 0.0*0.4 = 0.42 > 0.2 minimum
        $results = $this->strategy->match($extracted, $candidates);

        $this->assertCount(1, $results);
    }

    public function testMatchWeightsAreAppliedCorrectly(): void
    {
        // Artist matches exactly (1.0), but song and album are short unrelated strings
        // that won't match
        $extracted = new ExtractedMetadata(
            title: 'SongX',
            album: 'AlbumX',
            artist: 'ExactArtist',
        );

        $candidates = [
            [
                'title' => 'SongY',
                'artist' => 'ExactArtist',
                'album' => 'AlbumY',
            ],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        // Artist exact match = 1.0 * 0.3 = 0.3
        // Song: "songx" vs "songy" - similar but not exact, let's verify score > 0.2
        $this->assertCount(1, $results);
        $this->assertGreaterThan(0.3, $results[0]->confidence);
        $this->assertLessThanOrEqual(1.0, $results[0]->confidence);
    }

    public function testMatchWithMultipleCandidatesReturnsAllAboveThreshold(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Popular Song',
            album: 'Hit Album',
            artist: 'Famous Artist',
        );

        $candidates = [
            [
                'title' => 'Popular Song',
                'artist' => 'Famous Artist',
                'album' => 'Hit Album',
            ],
            [
                'title' => 'Popular Song',
                'artist' => 'Famous Artist',
                'album' => 'Hit Album Deluxe Edition',
            ],
            [
                'title' => 'Completely Unrelated Track',
                'artist' => 'Nobody Knows',
                'album' => 'Nothing Like This',
            ],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        // At least the two similar candidates should be included
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    public function testMatchReturnsMetadataMatchInstances(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Test Song',
            album: 'Test Album',
            artist: 'Test Artist',
        );

        $candidates = [
            [
                'title' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ],
        ];

        $results = $this->strategy->match($extracted, $candidates);

        $this->assertInstanceOf(\App\Metadata\Domain\Model\MetadataMatch::class, $results[0]);
    }

    public function testMatchPreservesCandidateData(): void
    {
        $extracted = new ExtractedMetadata(
            title: 'Test Song',
            album: 'Test Album',
            artist: 'Test Artist',
        );

        $candidate = [
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'trackNumber' => 5,
        ];

        $results = $this->strategy->match($extracted, [$candidate]);

        $this->assertSame($candidate, $results[0]->candidate);
    }
}
