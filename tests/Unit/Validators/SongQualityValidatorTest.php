<?php

namespace Tests\Unit\Validators;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\Song;
use App\Modules\Metadata\Matching\Validators\SongQualityValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongQualityValidatorTest extends TestCase
{
    private SongQualityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(SongQualityValidator::class);
    }

    #[Test]
    public function it_scores_perfect_title_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create(['title' => 'Test Song']);
        $artist = Artist::factory()->create();
        $song->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test Song',
            'artist' => $artist->name,
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        // Perfect title match should score reasonably high (title is only 35% of total)
        $this->assertGreaterThanOrEqual(0.5, $score);
    }

    #[Test]
    public function it_scores_lower_with_different_title(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create(['title' => 'Completely Different']);
        $artist = Artist::factory()->create();
        $song->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test Song',
            'artist' => $artist->name,
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        $this->assertLessThan(0.5, $score);
    }

    #[Test]
    public function it_validates_minimum_quality_threshold(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create();
        $artist = Artist::factory()->create();
        $song->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Random Title That Does Not Match',
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_identifies_high_confidence_matches(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create(['title' => 'Perfect Match', 'length' => 180000]);
        $artist = Artist::factory()->create();
        $song->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Perfect Match',
            'artist' => $artist->name,
            'length' => 180000,
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        $this->assertTrue($this->validator->isHighConfidenceMatch($metadata, $song, $score));
    }

    #[Test]
    public function it_handles_empty_metadata(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create();

        $metadata = [];

        $score = $this->validator->scoreMatch($metadata, $song);

        $this->assertEquals(0.0, $score);
        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_requires_title_for_valid_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create();

        $metadata = [
            'artist' => 'Some Artist',
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_handles_duration_within_tolerance(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create(['title' => 'Test', 'length' => 180000]);
        $artist = Artist::factory()->create();
        $song->artists()->attach($artist->id);

        // Within 10% tolerance
        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'length' => 175000,
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        // Should contribute positively to score
        $this->assertGreaterThan(0, $score);
    }

    #[Test]
    public function it_handles_songs_without_artists(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create(['title' => 'Test Song']);

        $metadata = [
            'title' => 'Test Song',
        ];

        $score = $this->validator->scoreMatch($metadata, $song);

        // Should still score based on title
        $this->assertGreaterThan(0, $score);
    }
}
