<?php

namespace Tests\Unit\Readers\Flac;

use App\Modules\Metadata\Readers\Flac\OggReader;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test OGG Vorbis reader with real OGG files
 */
class OggReaderTest extends TestCase
{
    private string $testDirectory = 'storage/muzak';

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_reads_ogg_metadata(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

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
    public function it_validates_ogg_file(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $this->assertTrue($reader->isValid(), "OGG file should be valid");
        $this->assertEquals('ogg', $reader->getFormat());
    }

    #[Test]
    public function it_handles_multiple_artists(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

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
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $track = $reader->getTrackNumber();

        // Track may or may not be present
        if ($track !== null) {
            $this->assertIsInt($track);
            $this->assertGreaterThan(0, $track);
        }
    }

    #[Test]
    public function it_reads_disc_number(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $disc = $reader->getDiscNumber();

        // Disc may or may not be present
        if ($disc !== null) {
            $this->assertIsInt($disc);
            $this->assertGreaterThan(0, $disc);
        }
    }

    #[Test]
    public function it_reads_genre(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $genre = $reader->getGenre();

        // Genre may or may not be present
        if ($genre !== null) {
            $this->assertIsString($genre);
        }
    }

    #[Test]
    public function it_reads_year_from_date_field(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $year = $reader->getYear();

        // Year may or may not be present
        if ($year !== null) {
            $this->assertIsString($year);
            $this->assertMatchesRegularExpression('/^\d{4}$/', $year, "Year should be 4 digits");
        }
    }

    #[Test]
    public function it_gets_comments(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

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
    public function it_has_vorbis_comments(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $this->assertTrue($reader->hasVorbisComments(), "Should have Vorbis comments");

        $commentBlock = $reader->getVorbisCommentBlock();
        $this->assertIsArray($commentBlock);
        $this->assertArrayHasKey('vendor', $commentBlock);
        $this->assertArrayHasKey('comments', $commentBlock);
    }

    #[Test]
    public function it_reads_vorbis_comments(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $comments = $reader->getVorbisComments();
        $this->assertIsArray($comments);

        // Should have at least some standard comments
        $this->assertNotEmpty($comments, "Should have some Vorbis comments");

        echo "\nFound Vorbis comments: " . implode(', ', array_keys($comments)) . "\n";
    }

    #[Test]
    public function it_gets_pictures(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $pictures = $reader->getPictures();
        $this->assertIsArray($pictures);

        if (!empty($pictures)) {
            $firstPicture = $reader->getFirstPicture();
            $this->assertNotNull($firstPicture);

            // Test that picture implements expected methods
            $this->assertIsInt($firstPicture->getImageType());
            $this->assertIsString($firstPicture->getMimeType());
            $this->assertIsString($firstPicture->getImageData());
        }
    }

    #[Test]
    public function it_gets_front_cover_image(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $frontCover = $reader->getFrontCoverImage();

        // Front cover may or may not be present
        if ($frontCover !== null) {
            $this->assertEquals(FlacPicture::IMAGE_COVER_FRONT, $frontCover->getImageType());
            $this->assertNotEmpty($frontCover->getImageData());
        }
    }

    #[Test]
    public function it_gets_pictures_by_type(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        // Test getting cover front pictures
        $coverFrontPictures = $reader->getPicturesByType(FlacPicture::IMAGE_COVER_FRONT);
        $this->assertIsArray($coverFrontPictures);

        foreach ($coverFrontPictures as $picture) {
            $this->assertEquals(FlacPicture::IMAGE_COVER_FRONT, $picture->getImageType());
        }
    }

    #[Test]
    public function it_reads_track_and_disc_totals(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $trackTotal = $reader->getTrackTotal();
        $discTotal = $reader->getDiscTotal();

        // These fields are optional
        if ($trackTotal !== null) {
            $this->assertIsInt($trackTotal);
            $this->assertGreaterThan(0, $trackTotal);
        }

        if ($discTotal !== null) {
            $this->assertIsInt($discTotal);
            $this->assertGreaterThan(0, $discTotal);
        }
    }

    #[Test]
    public function it_handles_track_slash_total_format(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        // If TRACKNUMBER is "3/12", getTrackNumber() should return 3
        $track = $reader->getTrackNumber();

        if ($track !== null) {
            $this->assertIsInt($track);
            // Should not contain the slash
            $this->assertStringNotContainsString('/', (string)$track);
        }
    }

    #[Test]
    public function it_handles_year_from_various_date_formats(): void
    {
        $testFile = $this->findOggFile();

        if (!$testFile) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $reader = new OggReader($testFile);

        $year = $reader->getYear();

        // If DATE field contains full date like "2023-05-15", should extract just the year
        if ($year !== null) {
            $this->assertMatchesRegularExpression('/^\d{4}$/', $year);
        }
    }

    #[Test]
    public function it_reads_multiple_ogg_files(): void
    {
        $files = glob($this->testDirectory . '/**/*.ogg');
        $files = array_merge($files, glob($this->testDirectory . '/**/*.oga'));

        if (empty($files)) {
            $this->markTestSkipped("No OGG test files found in {$this->testDirectory}");
        }

        $this->assertNotEmpty($files, "Should find OGG files");

        $validCount = 0;

        foreach ($files as $file) {
            try {
                $reader = new OggReader($file);

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
        $this->assertGreaterThan(0, $validCount, "Should successfully read at least one OGG file");
    }

    /**
     * Find first available OGG file for testing
     */
    private function findOggFile(): ?string
    {
        $files = glob($this->testDirectory . '/**/*.ogg');
        $files = array_merge($files, glob($this->testDirectory . '/**/*.oga'));

        return $files[0] ?? null;
    }
}
