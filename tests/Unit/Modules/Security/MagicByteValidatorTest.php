<?php

namespace Tests\Unit\Modules\Security;

use App\Modules\Security\MagicByteValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MagicByteValidatorTest extends TestCase
{
    private MagicByteValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MagicByteValidator();
    }

    #[Test]
    public function it_validates_mp3_files_with_id3_header(): void
    {
        $path = storage_path('test.mp3');

        // Create a test MP3 file with ID3 header
        $handle = fopen($path, 'wb');
        fwrite($handle, 'ID3' . str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidAudioFile($path));

        // Cleanup
        unlink($path);
    }

    #[Test]
    public function it_validates_flac_files(): void
    {
        $path = storage_path('test.flac');

        // Create a test FLAC file
        $handle = fopen($path, 'wb');
        fwrite($handle, 'fLaC' . str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidAudioFile($path));

        unlink($path);
    }

    #[Test]
    public function it_validates_ogg_files(): void
    {
        $path = storage_path('test.ogg');

        // Create a test OGG file
        $handle = fopen($path, 'wb');
        fwrite($handle, 'OggS' . str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidAudioFile($path));

        unlink($path);
    }

    #[Test]
    public function it_rejects_files_with_wrong_magic_bytes(): void
    {
        $path = storage_path('test.mp3');

        // Create a file with text content but .mp3 extension
        $handle = fopen($path, 'wb');
        fwrite($handle, 'This is not an MP3 file');
        fclose($handle);

        $this->assertFalse($this->validator->isValidAudioFile($path));

        unlink($path);
    }

    #[Test]
    public function it_detects_mp3_format(): void
    {
        $path = storage_path('test.mp3');

        $handle = fopen($path, 'wb');
        fwrite($handle, 'ID3' . str_repeat("\0", 256));
        fclose($handle);

        $detectedFormat = $this->validator->detectFormat($path);

        $this->assertEquals('mp3', $detectedFormat);

        unlink($path);
    }

    #[Test]
    public function it_categorizes_audio_formats(): void
    {
        $this->assertEquals('audio', $this->validator->getFormatCategory('mp3'));
        $this->assertEquals('audio', $this->validator->getFormatCategory('flac'));
        $this->assertEquals('audio', $this->validator->getFormatCategory('ogg'));
        $this->assertEquals('audio', $this->validator->getFormatCategory('wav'));
    }

    #[Test]
    public function it_categorizes_video_formats(): void
    {
        $this->assertEquals('video', $this->validator->getFormatCategory('mp4'));
        $this->assertEquals('video', $this->validator->getFormatCategory('mkv'));
        $this->assertEquals('video', $this->validator->getFormatCategory('avi'));
    }

    #[Test]
    public function it_categorizes_image_formats(): void
    {
        $this->assertEquals('image', $this->validator->getFormatCategory('jpg'));
        $this->assertEquals('image', $this->validator->getFormatCategory('png'));
        $this->assertEquals('image', $this->validator->getFormatCategory('gif'));
    }

    #[Test]
    public function it_validates_mp4_video_files(): void
    {
        $path = storage_path('test.mp4');

        // Create MP4 file with proper magic bytes
        $handle = fopen($path, 'wb');
        // Write ftyp box (first 4 bytes are offset, then magic bytes at offset 4)
        fwrite($handle, "\x00\x00\x00\x18\x66\x74\x79\x70\x69\x73\x6F\x6D");
        fwrite($handle, str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidVideoFile($path));

        unlink($path);
    }

    #[Test]
    public function it_validates_mkv_video_files(): void
    {
        $path = storage_path('test.mkv');

        // Create MKV file with EBML header
        $handle = fopen($path, 'wb');
        fwrite($handle, "\x1A\x45\xDF\xA3");
        fwrite($handle, str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidVideoFile($path));

        unlink($path);
    }

    #[Test]
    public function it_validates_jpeg_image_files(): void
    {
        $path = storage_path('test.jpg');

        // Create JPEG file with SOI marker
        $handle = fopen($path, 'wb');
        fwrite($handle, "\xFF\xD8\xFF");
        fwrite($handle, str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidImageFile($path));

        unlink($path);
    }

    #[Test]
    public function it_validates_png_image_files(): void
    {
        $path = storage_path('test.png');

        // Create PNG file with signature
        $handle = fopen($path, 'wb');
        fwrite($handle, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A");
        fwrite($handle, str_repeat("\0", 256));
        fclose($handle);

        $this->assertTrue($this->validator->isValidImageFile($path));

        unlink($path);
    }

    #[Test]
    public function it_validates_subtitle_files_by_extension(): void
    {
        $this->assertTrue($this->validator->isValidSubtitleFile('test.srt'));
        $this->assertTrue($this->validator->isValidSubtitleFile('test.ass'));
        $this->assertTrue($this->validator->isValidSubtitleFile('test.vtt'));
    }

    #[Test]
    public function it_returns_null_for_unsupported_formats(): void
    {
        $unsupportedPath = 'test.xyz';
        $detected = $this->validator->detectFormat($unsupportedPath);

        $this->assertNull($detected);
    }

    #[Test]
    public function it_returns_null_category_for_unsupported_formats(): void
    {
        $category = $this->validator->getFormatCategory('unsupported');

        $this->assertNull($category);
    }

    #[Test]
    public function it_validates_mime_consistency(): void
    {
        $path = storage_path('test.mp3');

        // Create MP3 file
        $handle = fopen($path, 'wb');
        fwrite($handle, 'ID3' . str_repeat("\0", 256));
        fclose($handle);

        // Should match audio MIME
        $this->assertTrue(
            $this->validator->validateAgainstMime($path, 'audio/mpeg')
        );

        // Should not match video MIME
        $this->assertFalse(
            $this->validator->validateAgainstMime($path, 'video/mp4')
        );

        unlink($path);
    }
}
