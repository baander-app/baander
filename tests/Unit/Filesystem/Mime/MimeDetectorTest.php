<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Mime;

use App\Filesystem\Mime\MimeDetector;
use PHPUnit\Framework\TestCase;

final class MimeDetectorTest extends TestCase
{
    private MimeDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MimeDetector();
    }

    /**
     * @return string 32-byte header padded with NULs
     */
    private function header(string $signature): string
    {
        return str_pad($signature, 32, "\x00");
    }

    // ---------- detectFromBytes: image signatures ----------

    public function testDetectFromBytesJpeg(): void
    {
        $this->assertSame('image/jpeg', $this->detector->detectFromBytes($this->header("\xFF\xD8\xFF")));
    }

    public function testDetectFromBytesPng(): void
    {
        $this->assertSame('image/png', $this->detector->detectFromBytes($this->header("\x89PNG\r\n\x1A\n")));
    }

    public function testDetectFromBytesGif87a(): void
    {
        $this->assertSame('image/gif', $this->detector->detectFromBytes($this->header('GIF87a')));
    }

    public function testDetectFromBytesGif89a(): void
    {
        $this->assertSame('image/gif', $this->detector->detectFromBytes($this->header('GIF89a')));
    }

    public function testDetectFromBytesTiffLittleEndian(): void
    {
        $this->assertSame('image/tiff', $this->detector->detectFromBytes($this->header("\x49\x49\x2A\x00")));
    }

    public function testDetectFromBytesTiffBigEndian(): void
    {
        $this->assertSame('image/tiff', $this->detector->detectFromBytes($this->header("\x4D\x4D\x00\x2A")));
    }

    public function testDetectFromBytesBmp(): void
    {
        $this->assertSame('image/bmp', $this->detector->detectFromBytes($this->header('BM')));
    }

    public function testDetectFromBytesSvgByXmlPrefix(): void
    {
        $this->assertSame('image/svg+xml', $this->detector->detectFromBytes($this->header('<?xml')));
    }

    public function testDetectFromBytesSvgBySvgPrefix(): void
    {
        $this->assertSame('image/svg+xml', $this->detector->detectFromBytes($this->header('<svg')));
    }

    // ---------- detectFromBytes: audio signatures ----------

    public function testDetectFromBytesMp3FrameFb(): void
    {
        $this->assertSame('audio/mpeg', $this->detector->detectFromBytes($this->header("\xFF\xFB")));
    }

    public function testDetectFromBytesMp3FrameF3(): void
    {
        $this->assertSame('audio/mpeg', $this->detector->detectFromBytes($this->header("\xFF\xF3")));
    }

    public function testDetectFromBytesMp3FrameF2(): void
    {
        $this->assertSame('audio/mpeg', $this->detector->detectFromBytes($this->header("\xFF\xF2")));
    }

    public function testDetectFromBytesMp3Id3(): void
    {
        $this->assertSame('audio/mpeg', $this->detector->detectFromBytes($this->header('ID3')));
    }

    public function testDetectFromBytesFlac(): void
    {
        $this->assertSame('audio/flac', $this->detector->detectFromBytes($this->header('fLaC')));
    }

    public function testDetectFromBytesAacF1(): void
    {
        $this->assertSame('audio/aac', $this->detector->detectFromBytes($this->header("\xFF\xF1")));
    }

    public function testDetectFromBytesAacF9(): void
    {
        $this->assertSame('audio/aac', $this->detector->detectFromBytes($this->header("\xFF\xF9")));
    }

    public function testDetectFromBytesOggWithoutPathReturnsAudioOgg(): void
    {
        // With no path, detectOggType() short-circuits to audio/ogg.
        $this->assertSame('audio/ogg', $this->detector->detectFromBytes($this->header('OggS')));
    }

    // ---------- detectFromBytes: RIFF container sub-types ----------

    public function testDetectFromBytesRiffWav(): void
    {
        $header = str_pad("RIFF\x00\x00\x00\x00WAVE", 32, "\x00");

        $this->assertSame('audio/wav', $this->detector->detectFromBytes($header));
    }

    public function testDetectFromBytesRiffAvi(): void
    {
        $header = str_pad("RIFF\x00\x00\x00\x00AVI ", 32, "\x00");

        $this->assertSame('video/avi', $this->detector->detectFromBytes($header));
    }

    public function testDetectFromBytesRiffWebp(): void
    {
        $header = str_pad("RIFF\x00\x00\x00\x00WEBP", 32, "\x00");

        $this->assertSame('image/webp', $this->detector->detectFromBytes($header));
    }

    public function testDetectFromBytesRiffUnknownSubtypeFallsBack(): void
    {
        $header = str_pad("RIFF\x00\x00\x00\x00XXXX", 32, "\x00");

        $this->assertSame('application/octet-stream', $this->detector->detectFromBytes($header));
    }

    // ---------- detectFromBytes: MP4/M4A ftyp brands ----------

    private function ftypHeader(string $brand): string
    {
        return str_pad("----ftyp" . $brand, 32, "\x00");
    }

    public function testDetectFromBytesFtypM4A(): void
    {
        $this->assertSame('audio/x-m4a', $this->detector->detectFromBytes($this->ftypHeader('M4A ')));
    }

    public function testDetectFromBytesFtypLowercaseM4a(): void
    {
        $this->assertSame('audio/x-m4a', $this->detector->detectFromBytes($this->ftypHeader('m4a ')));
    }

    public function testDetectFromBytesFtypIsom(): void
    {
        $this->assertSame('audio/x-m4a', $this->detector->detectFromBytes($this->ftypHeader('isom')));
    }

    public function testDetectFromBytesFtypM4V(): void
    {
        $this->assertSame('video/mp4', $this->detector->detectFromBytes($this->ftypHeader('M4V ')));
    }

    public function testDetectFromBytesFtypMp42WithoutPathDefaultsToVideoMp4(): void
    {
        $this->assertSame('video/mp4', $this->detector->detectFromBytes($this->ftypHeader('mp42')));
    }

    public function testDetectFromBytesFtypMp42WithM4aExtensionGuessesAudio(): void
    {
        $this->assertSame(
            'audio/x-m4a',
            $this->detector->detectFromBytes($this->ftypHeader('mp42'), 'song.m4a'),
        );
    }

    public function testDetectFromBytesFtypMp42WithM4vExtensionGuessesVideo(): void
    {
        $this->assertSame(
            'video/mp4',
            $this->detector->detectFromBytes($this->ftypHeader('mp42'), 'clip.m4v'),
        );
    }

    public function testDetectFromBytesFtypUnknownBrandDefaultsToVideoMp4(): void
    {
        $this->assertSame('video/mp4', $this->detector->detectFromBytes($this->ftypHeader('qt  ')));
    }

    // ---------- detectFromBytes: other containers ----------

    public function testDetectFromBytesMatroskaEbml(): void
    {
        // EBML magic maps to matroska (checked before webm in the signature list).
        $this->assertSame('video/x-matroska', $this->detector->detectFromBytes($this->header("\x1A\x45\xDF\xA3")));
    }

    public function testDetectFromBytesPdf(): void
    {
        $this->assertSame('application/pdf', $this->detector->detectFromBytes($this->header('%PDF')));
    }

    public function testDetectFromBytesSvgContentWithSvgExtensionPath(): void
    {
        // Header that contains (but does not start with) <svg is recognised via extension.
        $header = str_pad('   <svg xmlns', 32, "\x00");

        $this->assertSame('image/svg+xml', $this->detector->detectFromBytes($header, 'drawing.svg'));
    }

    // ---------- detect(): real temporary files ----------

    public function testDetectJpegFile(): void
    {
        $this->assertDetectFromTempFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 28), 'image/jpeg');
    }

    public function testDetectPngFile(): void
    {
        $this->assertDetectFromTempFile("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 24), 'image/png');
    }

    public function testDetectFlacFile(): void
    {
        $this->assertDetectFromTempFile('fLaC' . str_repeat("\x00", 28), 'audio/flac');
    }

    public function testDetectMp3Id3File(): void
    {
        $this->assertDetectFromTempFile('ID3' . str_repeat("\x00", 29), 'audio/mpeg');
    }

    public function testDetectMp3FrameFile(): void
    {
        $this->assertDetectFromTempFile("\xFF\xFB\x90" . str_repeat("\x00", 29), 'audio/mpeg');
    }

    public function testDetectGifFile(): void
    {
        $this->assertDetectFromTempFile('GIF89a' . str_repeat("\x00", 26), 'image/gif');
    }

    public function testDetectBmpFile(): void
    {
        $this->assertDetectFromTempFile('BM6' . str_repeat("\x00", 29), 'image/bmp');
    }

    public function testDetectPdfFile(): void
    {
        $this->assertDetectFromTempFile('%PDF-1.7' . str_repeat("\x00", 24), 'application/pdf');
    }

    public function testDetectWavFile(): void
    {
        $this->assertDetectFromTempFile(
            "RIFF\x00\x00\x00\x00WAVE" . str_repeat("\x00", 23),
            'audio/wav',
        );
    }

    public function testDetectWebpFile(): void
    {
        $this->assertDetectFromTempFile(
            "RIFF\x00\x00\x00\x00WEBP" . str_repeat("\x00", 23),
            'image/webp',
        );
    }

    public function testDetectM4aFile(): void
    {
        $this->assertDetectFromTempFile(
            "\x00\x00\x00\x20ftypM4A " . str_repeat("\x00", 20),
            'audio/x-m4a',
        );
    }

    public function testDetectOggVorbisFile(): void
    {
        $payload = 'OggS' . str_repeat("\x00", 24) . "\x01vorbis";

        $this->assertDetectFromTempFile($payload, 'audio/ogg');
    }

    public function testDetectOggOpusFile(): void
    {
        $payload = 'OggS' . str_repeat("\x00", 24) . 'OpusHead';

        $this->assertDetectFromTempFile($payload, 'audio/opus');
    }

    public function testDetectOggTheoraFile(): void
    {
        $payload = 'OggS' . str_repeat("\x00", 24) . "\x80theora";

        $this->assertDetectFromTempFile($payload, 'video/ogg');
    }

    public function testDetectNonExistentPathReturnsOctetStream(): void
    {
        $this->assertSame(
            'application/octet-stream',
            $this->detector->detect('/no/such/file/' . uniqid('mime_', true)),
        );
    }

    public function testDetectFileShorterThanFourBytesReturnsOctetStream(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mime_short_');
        assert(is_string($path));
        file_put_contents($path, 'abc');

        try {
            $this->assertSame('application/octet-stream', $this->detector->detect($path));
        } finally {
            @unlink($path);
        }
    }

    private function assertDetectFromTempFile(string $contents, string $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mime_');
        assert(is_string($path));
        file_put_contents($path, $contents);

        try {
            $this->assertSame($expected, $this->detector->detect($path));
        } finally {
            @unlink($path);
        }
    }

    // ---------- getExtension ----------

    public function testGetExtensionForKnownTypes(): void
    {
        $this->assertSame('jpg', $this->detector->getExtension('image/jpeg'));
        $this->assertSame('png', $this->detector->getExtension('image/png'));
        $this->assertSame('gif', $this->detector->getExtension('image/gif'));
        $this->assertSame('webp', $this->detector->getExtension('image/webp'));
        $this->assertSame('tiff', $this->detector->getExtension('image/tiff'));
        $this->assertSame('bmp', $this->detector->getExtension('image/bmp'));
        $this->assertSame('svg', $this->detector->getExtension('image/svg+xml'));
        $this->assertSame('mp3', $this->detector->getExtension('audio/mpeg'));
        $this->assertSame('flac', $this->detector->getExtension('audio/flac'));
        $this->assertSame('ogg', $this->detector->getExtension('audio/ogg'));
        $this->assertSame('wav', $this->detector->getExtension('audio/wav'));
        $this->assertSame('aac', $this->detector->getExtension('audio/aac'));
        $this->assertSame('m4a', $this->detector->getExtension('audio/x-m4a'));
        $this->assertSame('opus', $this->detector->getExtension('audio/opus'));
        $this->assertSame('mp4', $this->detector->getExtension('video/mp4'));
        $this->assertSame('mkv', $this->detector->getExtension('video/x-matroska'));
        $this->assertSame('webm', $this->detector->getExtension('video/webm'));
        $this->assertSame('avi', $this->detector->getExtension('video/avi'));
        $this->assertSame('pdf', $this->detector->getExtension('application/pdf'));
    }

    public function testGetExtensionForUnknownTypeReturnsBin(): void
    {
        $this->assertSame('bin', $this->detector->getExtension('application/x-unknown'));
    }

    // ---------- classification helpers ----------

    public function testIsAudioDetectsAudioMimeTypes(): void
    {
        $this->assertTrue($this->detector->isAudio('audio/mpeg'));
        $this->assertTrue($this->detector->isAudio('audio/flac'));
        $this->assertFalse($this->detector->isAudio('video/mp4'));
    }

    public function testIsVideoDetectsVideoMimeTypes(): void
    {
        $this->assertTrue($this->detector->isVideo('video/mp4'));
        $this->assertTrue($this->detector->isVideo('video/avi'));
        $this->assertFalse($this->detector->isVideo('audio/mpeg'));
    }

    public function testIsImageDetectsImageMimeTypes(): void
    {
        $this->assertTrue($this->detector->isImage('image/png'));
        $this->assertTrue($this->detector->isImage('image/jpeg'));
        $this->assertFalse($this->detector->isImage('application/pdf'));
    }

    public function testIsMediaMatchesAllMediaCategories(): void
    {
        $this->assertTrue($this->detector->isMedia('audio/mpeg'));
        $this->assertTrue($this->detector->isMedia('video/mp4'));
        $this->assertTrue($this->detector->isMedia('image/png'));
        $this->assertFalse($this->detector->isMedia('application/pdf'));
        $this->assertFalse($this->detector->isMedia('text/plain'));
    }
}
