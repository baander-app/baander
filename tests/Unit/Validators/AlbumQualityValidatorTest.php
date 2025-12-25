<?php

namespace Tests\Unit\Validators;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Modules\Metadata\Matching\Validators\AlbumQualityValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlbumQualityValidatorTest extends TestCase
{
    private AlbumQualityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(AlbumQualityValidator::class);
    }

    #[Test]
    public function it_scores_perfect_title_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'OK Computer']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'OK Computer',
            'artist' => $artist->name,
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Perfect title + artist match should score reasonably well
        $this->assertGreaterThan(0.5, $score);
    }

    #[Test]
    public function it_scores_case_insensitive_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'The Dark Side of the Moon']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'the dark side of the moon',
            'artist' => $artist->name,
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Case insensitive should still score reasonably
        $this->assertGreaterThan(0.5, $score);
    }

    #[Test]
    public function it_scores_partial_title_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Abbey Road']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Abbey Road [Remastered]',
            'artist' => $artist->name,
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Partial match should score moderately
        $this->assertGreaterThan(0.3, $score);
        $this->assertLessThan(0.8, $score);
    }

    #[Test]
    public function it_scores_year_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'year' => '2020',
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Year should add to score
        $this->assertGreaterThan(0.4, $score);
    }

    #[Test]
    public function it_scores_country_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'country' => 'US',
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Country should add to score
        $this->assertGreaterThan(0.4, $score);
    }

    #[Test]
    public function it_scores_track_count_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'tracklist' => [
                ['title' => 'Track 1'],
                ['title' => 'Track 2'],
                ['title' => 'Track 3'],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Track listing should add significantly to score
        $this->assertGreaterThan(0.5, $score);
    }

    #[Test]
    public function it_validates_minimum_quality_threshold(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Completely Different Title']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Some Album',
            'artist' => 'Different Artist Name',
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_identifies_high_confidence_matches(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'OK Computer', 'year' => 1997]);
        $artist = Artist::factory()->create(['name' => 'Radiohead']);
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'OK Computer',
            'artist' => 'Radiohead',
            'year' => '1997',
            'country' => 'Europe',
            'barcode' => '724358212821',
            'tracklist' => [
                ['title' => 'Airbag'],
                ['title' => 'Paranoid Android'],
                ['title' => 'Subterranean Homesick Alien'],
            ],
            'genres' => ['Alternative Rock', 'Electronic'],
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        $this->assertTrue($this->validator->isHighConfidenceMatch($metadata, $album, $score));
    }

    #[Test]
    public function it_handles_album_without_artists(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test Album']);

        $metadata = [
            'title' => 'Test Album',
            'year' => '2020',
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Should still score based on title and other metadata
        $this->assertGreaterThan(0.2, $score);
    }

    #[Test]
    public function it_handles_empty_metadata(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);

        $metadata = [];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Empty metadata should score very low
        $this->assertLessThan(0.1, $score);
        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_requires_title_for_valid_match(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'artist' => $artist->name,
            'year' => '2020',
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        $this->assertFalse($this->validator->isValidMatch($metadata, $score));
    }

    #[Test]
    public function it_scores_genre_and_additional_info(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'genres' => ['Rock', 'Pop'],
            'styles' => ['Indie Rock'],
            'mbid' => 'test-mbid-123',
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Additional metadata should improve score
        $this->assertGreaterThan(0.5, $score);
    }

    #[Test]
    public function it_scores_cover_art_presence(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'cover-art-archive' => true,
            'images' => [
                ['front' => 'http://example.com/cover.jpg'],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Cover art should improve score
        $this->assertGreaterThan(0.4, $score);
    }

    #[Test]
    public function it_handles_musicbrainz_artist_credit_format(): void
    {
        $library = Library::factory()->create();
        $artist1 = Artist::factory()->create(['name' => 'Thom Yorke']);
        $artist2 = Artist::factory()->create(['name' => 'Jonny Greenwood']);
        $album = Album::factory()->for($library)->create(['title' => 'Test Album']);
        $album->artists()->attach([$artist1->id, $artist2->id]);

        $metadata = [
            'title' => 'Test Album',
            'artist-credit' => [
                [
                    'artist' => ['name' => 'Thom Yorke'],
                ],
                [
                    'artist' => ['name' => 'Jonny Greenwood'],
                ],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Should match multiple artists
        $this->assertGreaterThan(0.3, $score);
    }

    #[Test]
    public function it_scores_multiple_artists(): void
    {
        $library = Library::factory()->create();
        $artist1 = Artist::factory()->create(['name' => 'Artist One']);
        $artist2 = Artist::factory()->create(['name' => 'Artist Two']);
        $album = Album::factory()->for($library)->create(['title' => 'Collaboration']);
        $album->artists()->attach([$artist1->id, $artist2->id]);

        $metadata = [
            'title' => 'Collaboration',
            'artists' => ['Artist One', 'Artist Two'],
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Should handle multiple artists
        $this->assertGreaterThan(0.5, $score);
    }

    #[Test]
    public function it_scores_media_format_with_tracks(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $metadata = [
            'title' => 'Test',
            'artist' => $artist->name,
            'media' => [
                [
                    'format' => 'CD',
                    'tracks' => [
                        ['title' => 'Track 1'],
                        ['title' => 'Track 2'],
                        ['title' => 'Track 3'],
                        ['title' => 'Track 4'],
                        ['title' => 'Track 5'],
                    ],
                ],
            ],
        ];

        $score = $this->validator->scoreMatch($metadata, $album);

        // Media with tracks should add significantly to score
        $this->assertGreaterThan(0.5, $score);
    }
}
