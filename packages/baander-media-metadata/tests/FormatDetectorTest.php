<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Tests;

use Baander\MediaMetadata\FormatDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

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

    #[DataProvider('additionalFormats')]
    public function testDetectsAdditionalContainers(string $header, string $format): void
    {
        self::assertSame($format, $this->detector->detect($this->tempFile($header, 'renamed.bin')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function additionalFormats(): iterable
    {
        yield 'ASF' => [hex2bin('3026b2758e66cf11a6d900aa0062ce6c'), 'wma'];
        yield 'AIFF' => ['FORM' . pack('N', 4) . 'AIFF', 'aiff'];
        yield 'AIFC' => ['FORM' . pack('N', 4) . 'AIFC', 'aiff'];
        yield 'ADTS' => ["\xff\xf1\x50\x80\x01\x7f\xfc", 'aac'];
        yield 'ID3 before ADTS' => ["ID3\x04\x00\x00\x00\x00\x00\x00\xff\xf1\x50\x80\x01\x7f\xfc", 'aac'];
    }

    #[DataProvider('extensionAliases')]
    public function testRecognizesAudioExtensionAliases(string $extension, string $format): void
    {
        self::assertSame($format, $this->detector->detect($this->tempFile('', 'track.' . $extension)));
    }

    /** @return iterable<array{string, string}> */
    public static function extensionAliases(): iterable
    {
        yield ['mp4a', 'm4a'];
        yield ['M4B', 'm4a'];
        yield ['wma', 'wma'];
        yield ['aac', 'aac'];
        yield ['aif', 'aiff'];
        yield ['AIFF', 'aiff'];
        yield ['aifc', 'aiff'];
        yield ['wave', 'wav'];
        yield ['oga', 'ogg'];
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
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }
}
