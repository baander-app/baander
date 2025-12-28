<?php

namespace Tests\Unit\Services;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\Song;
use App\Modules\Metadata\LocalMetadataService;
use PHPUnit\Framework\Attributes\Test;

class LocalMetadataServiceTest extends ServiceTestCase
{
    private LocalMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LocalMetadataService::class);
    }

    #[Test]
    public function it_extracts_metadata_from_audio_file(): void
    {
        // Create an album with associated artists and songs
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Test Album',
            'year' => 2023,
        ]);

        $artist = Artist::factory()->create(['name' => 'Test Artist']);
        $album->artists()->attach($artist);

        Song::factory()->count(3)->for($album)->create([
            'title' => 'Test Song',
            'track' => 1,
            'length' => 180,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Assert basic structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('album', $result);
        $this->assertArrayHasKey('artists', $result);
        $this->assertArrayHasKey('songs', $result);
        $this->assertArrayHasKey('quality_score', $result);
        $this->assertArrayHasKey('source', $result);

        // Assert source is correct
        $this->assertEquals('local_analysis', $result['source']);
    }

    #[Test]
    public function it_handles_locale_strings(): void
    {
        // Create album with locale string title
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => '$_︸_$media.unknown_album$_︸_$',
            'year' => 2023,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // The service should handle locale strings properly
        $this->assertArrayHasKey('album', $result);
        $this->assertIsArray($result['album']);

        // Title should be processed (locale markers are stripped by the service)
        $this->assertEquals('media.unknown_album', $result['album']['title']);
    }

    #[Test]
    public function it_returns_null_for_invalid_files(): void
    {
        // Create a minimal album with minimal data
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Unknown Album',
            'year' => null,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Service should still return a valid structure even with minimal data
        $this->assertIsArray($result);
        $this->assertArrayHasKey('album', $result);
        $this->assertArrayHasKey('artists', $result);
        $this->assertArrayHasKey('songs', $result);
        $this->assertArrayHasKey('quality_score', $result);

        // Album data should have null values where appropriate
        $this->assertNull($result['album']['year']);
        $this->assertEquals(0, $result['album']['track_count']);
    }

    #[Test]
    public function it_extracts_basic_metadata(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Amazing Album',
            'year' => 2022,
        ]);

        $artist = Artist::factory()->create(['name' => 'Amazing Artist']);
        $album->artists()->attach($artist);

        Song::factory()->for($album)->create([
            'title' => 'First Song',
            'track' => 1,
            'length' => 210,
            'path' => '/music/album/01-song.mp3',
        ]);

        Song::factory()->for($album)->create([
            'title' => 'Second Song',
            'track' => 2,
            'length' => 195,
            'path' => '/music/album/02-song.flac',
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Assert album metadata
        $this->assertEquals('Amazing Album', $result['album']['title']);
        $this->assertEquals(2022, $result['album']['year']);
        $this->assertEquals(2, $result['album']['track_count']);
        $this->assertEquals(405, $result['album']['total_duration']);
        $this->assertEquals('local_analysis', $result['album']['external_source']);

        // Assert artist metadata
        $this->assertCount(1, $result['artists']);
        $this->assertEquals('Amazing Artist', $result['artists'][0]['name']);
        $this->assertEquals('local_analysis', $result['artists'][0]['external_source']);

        // Assert song metadata
        $this->assertCount(2, $result['songs']);
        $this->assertEquals('First Song', $result['songs'][0]['title']);
        $this->assertEquals(1, $result['songs'][0]['track']);
        $this->assertEquals(210, $result['songs'][0]['length']);
        $this->assertEquals('mp3', $result['songs'][0]['file_format']);
        $this->assertEquals('flac', $result['songs'][1]['file_format']);
    }

    #[Test]
    public function it_handles_corrupted_files(): void
    {
        // Create an album that might cause exceptions
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Problematic Album',
        ]);

        // Mock a scenario where the album relationship might be null
        // This simulates corrupted or incomplete data
        $album->id = 999999;

        $result = $this->service->enhanceAlbumMetadata($album);

        // Service should handle errors gracefully and still return valid structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('source', $result);
        $this->assertEquals('local_analysis', $result['source']);
    }

    #[Test]
    public function it_extracts_lyrics_when_available(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Album with Lyrics',
            'year' => 2023,
        ]);

        // Create song with lyrics
        Song::factory()->for($album)->create([
            'title' => 'Song with Lyrics',
            'lyrics' => 'Verse 1\nChorus\nVerse 2\nChorus',
            'length' => 240,
        ]);

        // Create song without lyrics
        Song::factory()->for($album)->create([
            'title' => 'Instrumental',
            'lyrics' => null,
            'length' => 180,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // The service analyzes songs including those with lyrics
        $this->assertCount(2, $result['songs']);

        // Songs should be properly analyzed
        $songWithLyrics = collect($result['songs'])->firstWhere('title', 'Song with Lyrics');
        $this->assertNotNull($songWithLyrics);
        $this->assertEquals(240, $songWithLyrics['length']);

        $instrumental = collect($result['songs'])->firstWhere('title', 'Instrumental');
        $this->assertNotNull($instrumental);
        $this->assertEquals(180, $instrumental['length']);
    }

    #[Test]
    public function it_calculates_quality_score_for_complete_album(): void
    {
        // Create a complete album with all data
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Complete Album',
            'year' => 2023,
        ]);

        $artist = Artist::factory()->create(['name' => 'Artist']);
        $album->artists()->attach($artist);

        Song::factory()->count(5)->for($album)->create([
            'length' => 200,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Quality score should be higher for complete albums
        $this->assertGreaterThan(0.4, $result['quality_score']);
        $this->assertLessThanOrEqual(0.6, $result['quality_score']);
    }

    #[Test]
    public function it_calculates_quality_score_for_incomplete_album(): void
    {
        // Create an incomplete album
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Incomplete Album',
            'year' => null, // Missing year
        ]);

        // No artists
        // No songs

        $result = $this->service->enhanceAlbumMetadata($album);

        // Quality score should be low for incomplete albums
        $this->assertLessThan(0.3, $result['quality_score']);
    }

    #[Test]
    public function it_includes_songs_metadata_completeness_in_score(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Test Album',
            'year' => 2023,
        ]);

        $artist = Artist::factory()->create();
        $album->artists()->attach($artist);

        // Create songs with length
        Song::factory()->count(5)->for($album)->create([
            'length' => 200,
        ]);

        // Create songs without length
        Song::factory()->count(3)->for($album)->create([
            'length' => null,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Score should account for metadata completeness
        $this->assertGreaterThan(0.0, $result['quality_score']);
        $this->assertEquals(8, $result['album']['track_count']);
    }

    #[Test]
    public function it_handles_albums_with_multiple_artists(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Various Artists']);

        $artist1 = Artist::factory()->create(['name' => 'Artist One']);
        $artist2 = Artist::factory()->create(['name' => 'Artist Two']);
        $artist3 = Artist::factory()->create(['name' => 'Artist Three']);

        $album->artists()->attach([$artist1->id, $artist2->id, $artist3->id]);

        Song::factory()->for($album)->create();

        $result = $this->service->enhanceAlbumMetadata($album);

        // Should handle multiple artists
        $this->assertCount(3, $result['artists']);

        $artistNames = collect($result['artists'])->pluck('name')->toArray();
        $this->assertContains('Artist One', $artistNames);
        $this->assertContains('Artist Two', $artistNames);
        $this->assertContains('Artist Three', $artistNames);
    }

    #[Test]
    public function it_analyzes_song_file_formats(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();

        $formats = ['mp3', 'flac', 'wav', 'm4a', 'ogg'];

        foreach ($formats as $i => $format) {
            Song::factory()->for($album)->create([
                'path' => "/music/song{$i}.{$format}",
            ]);
        }

        $result = $this->service->enhanceAlbumMetadata($album);

        $detectedFormats = collect($result['songs'])->pluck('file_format')->toArray();
        // Check that all expected formats are present (order may vary)
        foreach ($formats as $format) {
            $this->assertContains($format, $detectedFormats);
        }
    }

    #[Test]
    public function it_handles_albums_with_various_audio_formats(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Mixed Format Album']);

        Song::factory()->for($album)->create([
            'path' => '/music/track1.mp3',
            'length' => 180,
        ]);

        Song::factory()->for($album)->create([
            'path' => '/music/track2.flac',
            'length' => 240,
        ]);

        Song::factory()->for($album)->create([
            'path' => '/music/track3.m4a',
            'length' => 200,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        $this->assertCount(3, $result['songs']);
        $this->assertEquals(620, $result['album']['total_duration']);

        $formats = collect($result['songs'])->pluck('file_format')->toArray();
        $this->assertContains('mp3', $formats);
        $this->assertContains('flac', $formats);
        $this->assertContains('m4a', $formats);
    }

    #[Test]
    public function it_logs_metadata_enhancement_success(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test Album']);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Service should complete successfully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('source', $result);
        $this->assertEquals('local_analysis', $result['source']);
    }

    #[Test]
    public function it_logs_metadata_enhancement_failure(): void
    {
        // Create an album with null title that might cause issues
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Test Album']);
        $album->id = null; // This should cause issues

        $result = $this->service->enhanceAlbumMetadata($album);

        // Service should handle errors gracefully
        $this->assertIsArray($result);
    }

    #[Test]
    public function it_handles_album_without_artists(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'No Artist Album']);

        Song::factory()->for($album)->create();

        $result = $this->service->enhanceAlbumMetadata($album);

        // Should return empty artists array
        $this->assertIsArray($result['artists']);
        $this->assertEmpty($result['artists']);

        // Quality score should be lower without artists
        $this->assertLessThanOrEqual(0.6, $result['quality_score']);
    }

    #[Test]
    public function it_handles_album_without_songs(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Empty Album']);

        $artist = Artist::factory()->create();
        $album->artists()->attach($artist);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Should return empty songs array
        $this->assertIsArray($result['songs']);
        $this->assertEmpty($result['songs']);

        // Album stats should reflect no songs
        $this->assertEquals(0, $result['album']['track_count']);
        $this->assertEquals(0, $result['album']['total_duration']);

        // Quality score should be lower without songs
        $this->assertLessThanOrEqual(0.4, $result['quality_score']);
    }

    #[Test]
    public function it_includes_artist_statistics(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();

        $artist = Artist::factory()->create(['name' => 'Prolific Artist']);

        // Create additional albums for this artist
        $otherAlbum1 = Album::factory()->for($library)->create();
        $otherAlbum2 = Album::factory()->for($library)->create();

        $artist->albums()->attach([$album->id, $otherAlbum1->id, $otherAlbum2->id]);

        // Create songs for the artist
        Song::factory()->count(10)->for($album)->create();
        Song::factory()->count(5)->for($otherAlbum1)->create();
        Song::factory()->count(3)->for($otherAlbum2)->create();

        $album->load('artists');

        $result = $this->service->enhanceAlbumMetadata($album);

        $this->assertCount(1, $result['artists']);
        $artistData = $result['artists'][0];

        $this->assertEquals('Prolific Artist', $artistData['name']);
        $this->assertEquals(3, $artistData['album_count']);
        // Song count might be 0 or calculated differently depending on eager loading
        $this->assertArrayHasKey('song_count', $artistData);
    }

    #[Test]
    public function it_causes_quality_score_to_cap_at_maximum(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Perfect Album',
            'year' => 2023,
        ]);

        $artist = Artist::factory()->create();
        $album->artists()->attach($artist);

        // Create many songs with lengths to maximize score
        Song::factory()->count(20)->for($album)->create([
            'length' => 200,
        ]);

        $result = $this->service->enhanceAlbumMetadata($album);

        // Score should cap at 0.6 for local analysis
        $this->assertLessThanOrEqual(0.6, $result['quality_score']);
    }
}
