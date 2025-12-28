<?php

namespace Tests\Unit\Readers\Id3;

use App\Modules\Metadata\Readers\Id3\Id3Reader;
use App\Modules\Metadata\Readers\Id3\Id3Picture;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test ID3 reader with real MP3 files
 */
class Id3ReaderTest extends TestCase
{
    private string $testFile = 'storage/muzak/Bulldogg - Bulldogg/03 - Knep Knep Drik Drik Hor Hor Snif Snif.mp3';

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_reads_id3_metadata(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        // Test basic metadata
        $title = $reader->getTitle();
        $this->assertNotNull($title, "Title should not be null");
        $this->assertIsString($title);

        $artist = $reader->getArtist();
        $this->assertNotNull($artist, "Artist should not be null");

        $artists = $reader->getArtists();
        $this->assertIsArray($artists);

        // Test album
        $album = $reader->getAlbum();
        $this->assertNotNull($album, "Album should not be null");
    }

    #[Test]
    public function it_detects_id3_version(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $version = $reader->getVersion();
        $this->assertNotNull($version, "Version should be detected");
        $this->assertStringContainsString('ID3', $version);
    }

    #[Test]
    public function it_validates_id3_file(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $this->assertTrue($reader->isValid(), "MP3 file should be valid");
        $this->assertEquals('id3', $reader->getFormat());
    }

    #[Test]
    public function it_handles_multiple_artists(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $artist = $reader->getArtist();
        $artists = $reader->getArtists();

        // getArtists() should always return an array
        $this->assertIsArray($artists);

        // getArtist() should return string for single artist, array for multiple
        if (count($artists) === 1) {
            $this->assertIsString($artist);
            $this->assertEquals($artists[0], $artist);
        } else {
            $this->assertIsArray($artist);
            $this->assertEquals($artists, $artist);
        }
    }

    #[Test]
    public function it_reads_track_number(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $track = $reader->getTrackNumber();

        // Track may or may not be present
        if ($track !== null) {
            $this->assertIsInt($track);
            $this->assertGreaterThan(0, $track);
        }
    }

    #[Test]
    public function it_reads_genre(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $genre = $reader->getGenre();

        // Genre may or may not be present
        if ($genre !== null) {
            $this->assertIsString($genre);
        }
    }

    #[Test]
    public function it_reads_year(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $year = $reader->getYear();

        // Year may or may not be present
        if ($year !== null) {
            $this->assertIsString($year);
        }
    }

    #[Test]
    public function it_gets_comments(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        // getComments() should always return array
        $comments = $reader->getComments();
        $this->assertIsArray($comments);

        // getComment() should return first comment or null
        $comment = $reader->getComment();
        if (!empty($comments)) {
            $this->assertEquals($comments[0], $comment);
        } else {
            $this->assertNull($comment);
        }
    }

    #[Test]
    public function it_reads_multiple_mp3_files(): void
    {
        $files = glob('storage/muzak/**/*.mp3');

        $this->assertNotEmpty($files, "Should find MP3 files");

        $validCount = 0;

        foreach ($files as $file) {
            try {
                $reader = new Id3Reader($file);

                // Assert basic metadata is readable
                $this->assertNotNull($reader->getTitle());
                $this->assertNotNull($reader->getArtist());

                $validCount++;
            } catch (\Exception $e) {
                $this->fail("Failed to read {$file}: {$e->getMessage()}");
            }

            if ($validCount >= 5) {
                break; // Test 5 files
            }
        }

        // Assert we successfully read at least one file
        $this->assertGreaterThan(0, $validCount, "Should successfully read at least one MP3 file");
    }

    #[Test]
    public function it_gets_all_tags(): void
    {
        if (!file_exists($this->testFile)) {
            $this->markTestSkipped("Test file not found: {$this->testFile}");
        }

        $reader = new Id3Reader($this->testFile);

        $tags = $reader->getTags();
        $this->assertIsArray($tags);

        // Debug: print all found tags
        echo "\nFound tags: " . implode(', ', array_keys($tags)) . "\n";

        // Should have at least some standard tags
        $this->assertNotEmpty($tags, "Should have some tags");
    }
}
