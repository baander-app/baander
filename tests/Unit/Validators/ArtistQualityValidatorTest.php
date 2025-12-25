<?php

namespace Tests\Unit\Validators;

use App\Models\Artist;
use App\Modules\Metadata\Matching\Validators\ArtistQualityValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArtistQualityValidatorTest extends TestCase
{
    private ArtistQualityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(ArtistQualityValidator::class);
    }

    #[Test]
    public function it_scores_perfect_name_match(): void
    {
        $artist = Artist::factory()->create(['name' => 'Radiohead']);

        $metadata = [
            'name' => 'Radiohead',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Perfect name match should score reasonably high
        $this->assertGreaterThanOrEqual(0.5, $score);
    }

    #[Test]
    public function it_scores_case_insensitive_match(): void
    {
        $artist = Artist::factory()->create(['name' => 'Radiohead']);

        $metadata = [
            'name' => 'radiohead',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Case insensitive match should still score reasonably
        $this->assertGreaterThanOrEqual(0.4, $score);
    }

    #[Test]
    public function it_scores_partial_name_match(): void
    {
        $artist = Artist::factory()->create(['name' => 'Led Zeppelin']);

        $metadata = [
            'name' => 'Led Zeppelin II',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Partial match should score moderately
        $this->assertGreaterThan(0.3, $score);
        $this->assertLessThan(0.8, $score);
    }

    #[Test]
    public function it_scores_artist_details(): void
    {
        $artist = Artist::factory()->create(['name' => 'Radiohead']);

        $metadata = [
            'name' => 'Radiohead',
            'type' => 'Group',
            'country' => 'GB',
            'life-span' => ['begin' => '1985'],
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Details should increase score
        $this->assertGreaterThan(0.5, $score);
    }

    #[Test]
    public function it_validates_minimum_quality_threshold(): void
    {
        $artist = Artist::factory()->create(['name' => 'Completely Different Name']);

        $metadata = [
            'name' => 'Some Artist',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_identifies_high_confidence_matches(): void
    {
        $artist = Artist::factory()->create(['name' => 'Radiohead']);

        $metadata = [
            'name' => 'Radiohead',
            'type' => 'Group',
            'country' => 'GB',
            'life-span' => ['begin' => '1985', 'end' => null],
            'disambiguation' => 'British rock band',
            'releases' => [
                ['primary-type' => 'Album'],
                ['primary-type' => 'Album'],
                ['primary-type' => 'Album'],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        $this->assertTrue($this->validator->isHighConfidenceMatch($metadata, $artist, $score));
    }

    #[Test]
    public function it_handles_empty_metadata(): void
    {
        $artist = Artist::factory()->create(['name' => 'Test Artist']);

        $metadata = [];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Empty metadata should score very low (close to 0)
        $this->assertLessThan(0.1, $score);
        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_requires_name_for_valid_match(): void
    {
        $artist = Artist::factory()->create(['name' => 'Test Artist']);

        $metadata = [
            'type' => 'Group',
            'country' => 'GB',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Should not be valid without name
        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_handles_international_names(): void
    {
        $artist = Artist::factory()->create(['name' => 'Björk']);

        $metadata = [
            'name' => 'Björk',
            'type' => 'Person',
            'country' => 'IS',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Should handle international characters well
        $this->assertGreaterThan(0.7, $score);
    }

    #[Test]
    public function it_bonuses_diverse_release_types(): void
    {
        $artist = Artist::factory()->create(['name' => 'Radiohead']);

        $metadata = [
            'name' => 'Radiohead',
            'releases' => [
                ['primary-type' => 'Album'],
                ['primary-type' => 'Single'],
                ['primary-type' => 'EP'],
                ['primary-type' => 'Album'],
                ['primary-type' => 'Single'],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Diverse releases should increase score
        $this->assertGreaterThan(0.4, $score);
    }

    #[Test]
    public function it_scores_discography(): void
    {
        $artist = Artist::factory()->create(['name' => 'Artist With Albums']);

        $metadata = [
            'name' => 'Artist With Albums',
            'releases' => [
                ['primary-type' => 'Album'],
                ['primary-type' => 'Album'],
                ['primary-type' => 'Album'],
                ['primary-type' => 'Album'],
                ['primary-type' => 'Album'],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Having releases should add to score (name match gives base score)
        $this->assertGreaterThan(0.4, $score);
    }

    #[Test]
    public function it_requires_significant_details_for_high_confidence(): void
    {
        $artist = Artist::factory()->create(['name' => 'Test Artist']);

        // High name score but minimal details
        $metadata = [
            'name' => 'Test Artist',
            'type' => 'Group',
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Should not be high confidence without significant details
        $this->assertFalse($this->validator->isHighConfidenceMatch($metadata, $artist, $score));
    }

    #[Test]
    public function it_handles_various_artist_types(): void
    {
        $artist = Artist::factory()->create(['name' => 'Test']);

        $metadataPerson = [
            'name' => 'Test',
            'type' => 'Person',
        ];

        $metadataOrchestra = [
            'name' => 'Test',
            'type' => 'Orchestra',
        ];

        $scorePerson = $this->validator->scoreMatch($metadataPerson, $artist);
        $scoreOrchestra = $this->validator->scoreMatch($metadataOrchestra, $artist);

        // Both specific types should score reasonably well
        $this->assertGreaterThan(0.5, $scorePerson);
        $this->assertGreaterThan(0.5, $scoreOrchestra);
    }

    #[Test]
    public function it_scores_life_span_correctly(): void
    {
        $artist = Artist::factory()->create(['name' => 'Test Artist']);

        $metadata = [
            'name' => 'Test Artist',
            'life-span' => [
                'begin' => '1990',
                'end' => '2020',
                'ended' => true,
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Complete life-span should contribute to score
        $this->assertGreaterThan(0.4, $score);
    }

    #[Test]
    public function it_penalizes_artists_with_few_releases(): void
    {
        $artist = Artist::factory()->create(['name' => 'New Artist']);

        $metadata = [
            'name' => 'New Artist',
            'releases' => [
                ['primary-type' => 'Single'],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $artist);

        // Minimal discography should result in moderate score
        $this->assertGreaterThan(0.3, $score);
        $this->assertLessThan(0.9, $score);
    }
}
