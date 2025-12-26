<?php

namespace Tests\Unit\Readers\Flac;

use App\Modules\Metadata\Readers\Flac\FlacReader;
use App\Modules\Metadata\Readers\FormatDetector;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test FLAC reader with real BABYMETAL FLAC files
 */
class FlacReaderTest extends TestCase
{
    private string $testDirectory = 'storage/muzak/BABYMETAL - BABYMETAL';
    private FormatDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new FormatDetector();
    }

    #[Test]
    public function it_detects_flac_format(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $format = $this->detector->detect($testFile);

        $this->assertEquals('flac', $format, "File should be detected as FLAC");
    }

    #[Test]
    public function it_reads_flac_metadata(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        // Test basic metadata fields
        $title = $reader->getTitle();
        $this->assertNotNull($title, "Title should not be null");
        $this->assertIsString($title);

        $album = $reader->getAlbum();
        $this->assertNotNull($album, "Album should not be null");
        $this->assertIsString($album);

        $artist = $reader->getArtist();
        $this->assertNotNull($artist, "Artist should not be null");

        // Test artists array
        $artists = $reader->getArtists();
        $this->assertIsArray($artists);
        $this->assertNotEmpty($artists);

        // Test track number
        $track = $reader->getTrackNumber();
        $this->assertNotNull($track, "Track number should not be null");
        $this->assertIsInt($track);
    }

    #[Test]
    public function it_handles_multiple_artists(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

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
    public function it_reads_pictures_from_flac(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $images = $reader->getImages();
        $this->assertIsArray($images);

        // Assert we have at least one picture
        $this->assertNotEmpty($images, "FLAC file should contain at least one picture");

        // Test first picture structure
        $firstImage = $images[0];
        $this->assertInstanceOf(FlacPicture::class, $firstImage);

        // Test picture properties
        $this->assertIsInt($firstImage->getImageType());
        $this->assertIsString($firstImage->getMimeType());
        $this->assertGreaterThan(0, $firstImage->getImageSize());
        $this->assertGreaterThan(0, $firstImage->getWidth());
        $this->assertGreaterThan(0, $firstImage->getHeight());
    }

    #[Test]
    public function it_gets_front_cover_image(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $cover = $reader->getFrontCoverImage();

        // Should find a cover image (tries IMAGE_COVER_FRONT first, falls back to first picture)
        $this->assertNotNull($cover, "Should find a cover image");
        $this->assertInstanceOf(FlacPicture::class, $cover);

        // Should have valid image properties
        $this->assertGreaterThan(0, $cover->getImageSize());
        $this->assertGreaterThan(0, $cover->getWidth());
        $this->assertGreaterThan(0, $cover->getHeight());
        $this->assertIsString($cover->getMimeType());
    }

    #[Test]
    public function it_gets_stream_info(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $streamInfo = $reader->getStreamInfo();

        $this->assertNotNull($streamInfo, "Stream info should not be null");
        $this->assertIsArray($streamInfo);

        // Assert required stream info fields
        $this->assertArrayHasKey('sampleRate', $streamInfo);
        $this->assertArrayHasKey('channels', $streamInfo);
        $this->assertArrayHasKey('bitsPerSample', $streamInfo);
        $this->assertArrayHasKey('totalSamples', $streamInfo);

        // Assert stream info values are valid
        $this->assertGreaterThan(0, $streamInfo['sampleRate']);
        $this->assertGreaterThan(0, $streamInfo['channels']);
        $this->assertGreaterThan(0, $streamInfo['bitsPerSample']);
        $this->assertGreaterThan(0, $streamInfo['totalSamples']);
    }

    #[Test]
    public function it_gets_vorbis_comments(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $comments = $reader->getVorbisComments();

        $this->assertIsArray($comments);
        $this->assertNotEmpty($comments, "FLAC file should contain Vorbis comments");

        // Assert standard comment fields exist
        $this->assertArrayHasKey('TITLE', $comments);
        $this->assertArrayHasKey('ARTIST', $comments);
        $this->assertArrayHasKey('ALBUM', $comments);
    }

    #[Test]
    public function it_gets_metadata_blocks(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $blocks = $reader->getMetadataBlocks();

        $this->assertIsArray($blocks);
        $this->assertNotEmpty($blocks, "FLAC file should contain metadata blocks");

        // Assert block structure
        foreach ($blocks as $block) {
            $this->assertArrayHasKey('type', $block);
            $this->assertArrayHasKey('length', $block);
            $this->assertArrayHasKey('isLast', $block);
            $this->assertIsInt($block['length']);
            $this->assertIsBool($block['isLast']);
        }

        // First block should always be STREAMINFO
        $this->assertEquals('STREAMINFO', $blocks[0]['type']);
    }

    #[Test]
    public function it_validates_flac_file(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $this->assertTrue($reader->isValid(), "FLAC file should be valid");
        $this->assertEquals('flac', $reader->getFormat());
        $this->assertEquals($testFile, $reader->getFilePath());
    }

    #[Test]
    public function it_reads_multiple_flac_files(): void
    {
        $files = glob($this->testDirectory . '/*.flac');

        $this->assertNotEmpty($files, "Should find FLAC files in test directory");

        $validCount = 0;

        foreach ($files as $file) {
            // Skip Zone.Identifier files
            if (str_ends_with($file, ':Zone.Identifier')) {
                continue;
            }

            $format = $this->detector->detect($file);

            if ($format === 'flac') {
                $reader = new FlacReader($file);

                // Assert basic metadata is readable
                $this->assertNotNull($reader->getTitle());
                $this->assertNotNull($reader->getAlbum());
                $this->assertNotEmpty($reader->getArtists());

                $validCount++;
            }
        }

        // Assert we successfully read at least one FLAC file
        $this->assertGreaterThan(0, $validCount, "Should successfully read at least one FLAC file");
    }

    #[Test]
    public function it_handles_track_number_with_total(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $trackNumber = $reader->getTrackNumber();

        // Should extract just the track number from "1/13" format
        $this->assertIsInt($trackNumber);
        $this->assertGreaterThan(0, $trackNumber);
    }

    #[Test]
    public function it_gets_genre(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $genre = $reader->getGenre();

        // Genre may or may not be present
        if ($genre !== null) {
            $this->assertIsString($genre);
        }
    }

    #[Test]
    public function it_gets_year(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

        $year = $reader->getYear();

        // Year should be present for this file
        $this->assertNotNull($year, "Year should be present");
        $this->assertIsString($year);
    }

    #[Test]
    public function it_handles_comments(): void
    {
        $testFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file not found: {$testFile}");
        }

        $reader = new FlacReader($testFile);

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
}
