<?php

namespace Tests\Unit\Readers\Flac;

use App\Modules\Metadata\Readers\Flac\FlacReader;
use App\Modules\Metadata\Readers\Flac\FlacWriter;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test FLAC writer with real BABYMETAL FLAC files
 *
 * WARNING: These tests create temporary copies of FLAC files and modify them.
 * The original files are never modified.
 */
class FlacWriterTest extends TestCase
{
    private string $testDirectory = 'storage/muzak/BABYMETAL - BABYMETAL';
    private string $tempDirectory = 'storage/temp/test';
    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceFile = $this->testDirectory . '/01 BABYMETAL - BABYMETAL DEATH.flac';

        if (!file_exists($this->sourceFile)) {
            $this->markTestSkipped("Test file not found: {$this->sourceFile}");
        }

        // Create temp directory if it doesn't exist
        if (!is_dir($this->tempDirectory)) {
            mkdir($this->tempDirectory, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (is_dir($this->tempDirectory)) {
            $files = glob($this->tempDirectory . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Create a temporary copy of the source file for testing
     */
    private function createTempFile(): string
    {
        $tempFile = $this->tempDirectory . '/' . uniqid('test_') . '.flac';
        copy($this->sourceFile, $tempFile);
        return $tempFile;
    }

    #[Test]
    public function it_sets_title(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setTitle('Test Title');
        $writer->write(false); // No backup for test

        // Verify the change
        $reader = new FlacReader($tempFile);
        $this->assertEquals('Test Title', $reader->getTitle());
    }

    #[Test]
    public function it_sets_artist(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setArtist('Test Artist');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $artists = $reader->getArtists();
        $this->assertEquals(['Test Artist'], $artists);
    }

    #[Test]
    public function it_sets_multiple_artists(): void
    {
        $tempFile = $this->createTempFile();

        $artists = ['Artist 1', 'Artist 2', 'Artist 3'];
        $writer = new FlacWriter($tempFile);
        $writer->setArtist($artists);
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals($artists, $reader->getArtists());
    }

    #[Test]
    public function it_sets_album(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setAlbum('Test Album');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('Test Album', $reader->getAlbum());
    }

    #[Test]
    public function it_sets_genre(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setGenre('Test Genre');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('Test Genre', $reader->getGenre());
    }

    #[Test]
    public function it_sets_year(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setYear('2024');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('2024', $reader->getYear());
    }

    #[Test]
    public function it_sets_track_number(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setTrackNumber(5);
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals(5, $reader->getTrackNumber());
    }

    #[Test]
    public function it_sets_track_number_with_total(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setTrackNumber(5, 12);
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals(5, $reader->getTrackNumber());

        // Verify the format in comments
        $comments = $reader->getVorbisComments();
        $this->assertArrayHasKey('TRACKNUMBER', $comments);
        $this->assertEquals('5/12', $comments['TRACKNUMBER'][0]);
    }

    #[Test]
    public function it_sets_disc_number(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setDiscNumber(2, 3);
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals(2, $reader->getDiscNumber());

        // Verify the format in comments
        $comments = $reader->getVorbisComments();
        $this->assertArrayHasKey('DISCNUMBER', $comments);
        $this->assertEquals('2/3', $comments['DISCNUMBER'][0]);
    }

    #[Test]
    public function it_sets_comment(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setComment('This is a test comment');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('This is a test comment', $reader->getComment());
    }

    #[Test]
    public function it_sets_multiple_fields_at_once(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setFields([
            'TITLE' => 'New Title',
            'ARTIST' => 'New Artist',
            'ALBUM' => 'New Album',
            'GENRE' => 'Rock',
        ]);
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('New Title', $reader->getTitle());
        $this->assertEquals('New Artist', $reader->getArtists()[0]);
        $this->assertEquals('New Album', $reader->getAlbum());
        $this->assertEquals('Rock', $reader->getGenre());
    }

    #[Test]
    public function it_sets_custom_field(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setField('CUSTOMFIELD', 'Custom Value');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $comments = $reader->getVorbisComments();
        $this->assertArrayHasKey('CUSTOMFIELD', $comments);
        $this->assertEquals(['Custom Value'], $comments['CUSTOMFIELD']);
    }

    #[Test]
    public function it_removes_field(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->removeField('GENRE');
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $comments = $reader->getVorbisComments();

        // GENRE should not be in comments
        $this->assertArrayNotHasKey('GENRE', $comments);
    }

    #[Test]
    public function it_preserves_audio_data(): void
    {
        $tempFile = $this->createTempFile();

        // Get original file hash
        $originalReader = new FlacReader($this->sourceFile);
        $originalStreamInfo = $originalReader->getStreamInfo();

        $writer = new FlacWriter($tempFile);
        $writer->setTitle('Modified Title');
        $writer->write(false);

        // Verify audio data is preserved
        $modifiedReader = new FlacReader($tempFile);
        $modifiedStreamInfo = $modifiedReader->getStreamInfo();

        $this->assertEquals($originalStreamInfo['sampleRate'], $modifiedStreamInfo['sampleRate']);
        $this->assertEquals($originalStreamInfo['channels'], $modifiedStreamInfo['channels']);
        $this->assertEquals($originalStreamInfo['bitsPerSample'], $modifiedStreamInfo['bitsPerSample']);
        $this->assertEquals($originalStreamInfo['totalSamples'], $modifiedStreamInfo['totalSamples']);
    }

    #[Test]
    public function it_creates_backup(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setTitle('Test');
        $writer->write(true); // Create backup

        // Backup file should exist
        $this->assertFileExists($tempFile . '.bak');

        // Clean up backup
        unlink($tempFile . '.bak');
    }

    #[Test]
    public function it_updates_existing_field(): void
    {
        $tempFile = $this->createTempFile();

        // Get original title
        $reader = new FlacReader($tempFile);
        $originalTitle = $reader->getTitle();

        // Set new title
        $writer = new FlacWriter($tempFile);
        $writer->setTitle('Updated Title');
        $writer->write(false);

        // Verify title was updated (not duplicated)
        $updatedReader = new FlacReader($tempFile);
        $comments = $updatedReader->getVorbisComments();

        $this->assertEquals(['Updated Title'], $comments['TITLE']);
        $this->assertCount(1, $comments['TITLE']); // Should only have one TITLE entry
    }

    #[Test]
    public function it_handles_unicode_characters(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);
        $writer->setFields([
            'TITLE' => '日本語タイトル',
            'ARTIST' => 'Балтимор',
            'ALBUM' => 'Cañón',
        ]);
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('日本語タイトル', $reader->getTitle());
        $this->assertEquals('Балтимор', $reader->getArtists()[0]);
        $this->assertEquals('Cañón', $reader->getAlbum());
    }

    #[Test]
    public function it_is_chainable(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);

        // Test method chaining
        $result = $writer
            ->setTitle('Chain Test')
            ->setArtist('Chain Artist')
            ->setAlbum('Chain Album')
            ->setGenre('Test Genre')
            ->setYear('2024')
            ->setTrackNumber(1)
            ->setComment('Chain Comment')
            ->write(false);

        $this->assertTrue($result);

        $reader = new FlacReader($tempFile);
        $this->assertEquals('Chain Test', $reader->getTitle());
        $this->assertEquals('Chain Artist', $reader->getArtists()[0]);
        $this->assertEquals('Chain Album', $reader->getAlbum());
    }

    #[Test]
    public function it_normalizes_field_names(): void
    {
        $tempFile = $this->createTempFile();

        $writer = new FlacWriter($tempFile);

        // Test various capitalizations
        $writer->setField('title', 'Test 1');
        $writer->setField('Title', 'Test 2'); // Should overwrite
        $writer->setField('TITLE', 'Test 3'); // Should overwrite
        $writer->write(false);

        $reader = new FlacReader($tempFile);
        $comments = $reader->getVorbisComments();

        // Should only have one TITLE field
        $this->assertCount(1, $comments['TITLE']);
        $this->assertEquals('Test 3', $comments['TITLE'][0]);
    }
}
