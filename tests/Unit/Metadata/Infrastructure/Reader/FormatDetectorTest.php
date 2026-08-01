<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Infrastructure\Reader\FormatDetector;
use PHPUnit\Framework\TestCase;

final class FormatDetectorTest extends TestCase
{
    private FormatDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FormatDetector();
    }

    public function testDetectsFlacByMagicBytes(): void
    {
        $file = $this->tempFile("\x66\x4C\x61\x43" . "\x00\x00\x00\x22");

        $this->assertSame('flac', $this->detector->detect($file));
    }

    public function testDetectsMp3ById3v2Header(): void
    {
        $file = $this->tempFile("ID3\x04\x00\x00\x00\x00\x00\x00");

        $this->assertSame('mp3', $this->detector->detect($file));
    }

    public function testDetectsMp3BySyncWord(): void
    {
        $file = $this->tempFile("\xFF\xFB\x90\x64");

        $this->assertSame('mp3', $this->detector->detect($file));
    }

    public function testDetectsOggByMagicBytes(): void
    {
        $file = $this->tempFile("OggS\x00\x02");

        $this->assertSame('ogg', $this->detector->detect($file));
    }

    public function testDetectsM4aByFtypBox(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x20\x66\x74\x79\x70" . "isom");

        $this->assertSame('m4a', $this->detector->detect($file));
    }

    public function testDetectsWavByMagicBytes(): void
    {
        $file = $this->tempFile("RIFF\x00\x00\x00\x00WAVE" . "\x00\x00\x00\x00");

        $this->assertSame('wav', $this->detector->detect($file));
    }

    public function testFallsBackToExtensionForFlac(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'test.flac');

        $this->assertSame('flac', $this->detector->detect($file));
    }

    public function testFallsBackToExtensionForMp3(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'track.mp3');

        $this->assertSame('mp3', $this->detector->detect($file));
    }

    public function testFallsBackToExtensionForOgg(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'audio.ogg');

        $this->assertSame('ogg', $this->detector->detect($file));
    }

    public function testFallsBackToExtensionForM4a(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'song.m4a');

        $this->assertSame('m4a', $this->detector->detect($file));
    }

    public function testFallsBackToExtensionForWav(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'audio.wav');

        $this->assertSame('wav', $this->detector->detect($file));
    }

    public function testFallsBackToExtensionForOga(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'audio.oga');

        $this->assertSame('ogg', $this->detector->detect($file));
    }

    public function testReturnsNullForUnknownFormat(): void
    {
        $file = $this->tempFile("\x00\x00\x00\x00", 'document.txt');

        $this->assertNull($this->detector->detect($file));
    }

    public function testReturnsNullForNonExistentFile(): void
    {
        $this->assertNull($this->detector->detect('/nonexistent/path/file'));
    }

    public function testMagicBytesTakePrecedenceOverExtension(): void
    {
        $file = $this->tempFile("\x66\x4C\x61\x43\x00", 'renamed.txt');

        $this->assertSame('flac', $this->detector->detect($file));
    }

    private function tempFile(string $content, string $name = 'test.bin'): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $path = sys_get_temp_dir() . '/' . $base . '_' . uniqid() . '.' . $ext;
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/*_*') as $file) {
            if (is_file($file) && str_contains(basename($file), '_')) {
                @unlink($file);
            }
        }
    }
}
