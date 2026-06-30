<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Infrastructure\Reader\Id3Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Id3ParserTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testParsesId3v2WithTextFrames(): void
    {
        $frames = $this->buildFrame('TIT2', "\x00Test Song")
               . $this->buildFrame('TPE1', "\x00Test Artist")
               . $this->buildFrame('TALB', "\x00Test Album")
               . $this->buildFrame('TDRC', "\x002024");

        $file = $this->buildId3v2File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertSame('ID3v2.3.0', $parser->getVersion());
        $this->assertSame(['Test Song'], $parser->getTags()['TITLE']);
        $this->assertSame(['Test Artist'], $parser->getTags()['ARTIST']);
        $this->assertSame(['Test Album'], $parser->getTags()['ALBUM']);
        $this->assertSame(['2024'], $parser->getTags()['YEAR']);
    }

    public function testParsesMultipleValuesForSameFrame(): void
    {
        $frames = $this->buildFrame('TPE1', "\x00Artist One")
               . $this->buildFrame('TPE1', "\x00Artist Two");

        $file = $this->buildId3v2File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertSame(['Artist One', 'Artist Two'], $parser->getTags()['ARTIST']);
    }

    public function testParsesAlbumArtistFrame(): void
    {
        $frames = $this->buildFrame('TPE2', "\x00Various Artists");

        $file = $this->buildId3v2File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertSame(['Various Artists'], $parser->getTags()['ALBUMARTIST']);
    }

    public function testParsesApicPictureFrame(): void
    {
        $imageData = "\xFF\xD8\xFF\xE0\x00\x10JFIF";
        $apicData = "\x00image/jpeg\x00\x03front cover\x00" . $imageData;

        $frames = $this->buildFrame('APIC', $apicData);
        $file = $this->buildId3v2File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $pictures = $parser->getPictures();
        $this->assertCount(1, $pictures);
        $this->assertSame(3, $pictures[0]['type']);
        $this->assertSame('image/jpeg', $pictures[0]['mimeType']);
        $this->assertSame('front cover', $pictures[0]['description']);
        $this->assertSame($imageData, $pictures[0]['imageData']);
        $this->assertSame(strlen($imageData), $pictures[0]['imageSize']);
    }

    public function testParsesId3v1AsFallback(): void
    {
        $file = $this->buildId3v1File(
            title: 'V1 Title',
            artist: 'V1 Artist',
            album: 'V1 Album',
            year: '1999',
            track: 5,
        );

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertSame('ID3v1', $parser->getVersion());
        $this->assertSame(['V1 Title'], $parser->getTags()['TITLE']);
        $this->assertSame(['V1 Artist'], $parser->getTags()['ARTIST']);
        $this->assertSame(['V1 Album'], $parser->getTags()['ALBUM']);
        $this->assertSame(['1999'], $parser->getTags()['YEAR']);
        $this->assertSame(['5'], $parser->getTags()['TRACKNUMBER']);
    }

    public function testId3v1PadsFieldsToExactLength(): void
    {
        $file = $this->buildId3v1File(title: 'Short');
        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertSame(['Short'], $parser->getTags()['TITLE']);
    }

    public function testThrowsOnNoId3Tags(): void
    {
        $file = $this->tempFile(str_repeat("\x00", 256), 'no_id3.bin');

        $parser = new Id3Parser($file, $this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No ID3 tags found');
        $parser->parse();
    }

    public function testThrowsOnNonExistentFile(): void
    {
        $parser = new Id3Parser('/nonexistent/file.mp3', $this->logger);

        $this->expectException(\RuntimeException::class);
        $parser->parse();
    }

    public function testFrameMappings(): void
    {
        $frames = $this->buildFrame('TCON', "\x00Rock")
               . $this->buildFrame('TRCK', "\x005")
               . $this->buildFrame('TPOS', "\x001")
               . $this->buildFrame('TBPM', "\x00120")
               . $this->buildFrame('TCOM', "\x00Songwriter")
               . $this->buildFrame('TLEN', "\x00225000");

        $file = $this->buildId3v2File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertSame(['Rock'], $parser->getTags()['GENRE']);
        $this->assertSame(['5'], $parser->getTags()['TRACKNUMBER']);
        $this->assertSame(['1'], $parser->getTags()['DISCNUMBER']);
        $this->assertSame(['120'], $parser->getTags()['BPM']);
        $this->assertSame(['Songwriter'], $parser->getTags()['COMPOSER']);
        $this->assertSame(['225000'], $parser->getTags()['LENGTH']);
    }

    public function testNoPicturesParsedWhenNonePresent(): void
    {
        $frames = $this->buildFrame('TIT2', "\x00No Art Here");
        $file = $this->buildId3v2File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertSame([], $parser->getPictures());
    }

    // ---- Helpers ----

    private function buildId3v2File(string $frameData): string
    {
        $size = strlen($frameData);
        $header = "ID3\x03\x00\x00"
            . chr(($size >> 21) & 0x7F)
            . chr(($size >> 14) & 0x7F)
            . chr(($size >> 7) & 0x7F)
            . chr($size & 0x7F);

        return $this->tempFile($header . $frameData, 'id3v2.mp3');
    }

    private function buildFrame(string $id, string $data): string
    {
        return $id . pack('N', strlen($data)) . "\x00\x00" . $data;
    }

    private function buildId3v1File(
        string $title = '',
        string $artist = '',
        string $album = '',
        string $year = '',
        string $comment = '',
        int $track = 0,
    ): string {
        $tag = 'TAG'
            . str_pad(substr($title, 0, 30), 30, "\x00")
            . str_pad(substr($artist, 0, 30), 30, "\x00")
            . str_pad(substr($album, 0, 30), 30, "\x00")
            . str_pad(substr($year, 0, 4), 4, "\x00")
            . str_pad(substr($comment, 0, 28), 28, "\x00")
            . chr($track)
            . "\x00"    // padding (ID3v1.1)
            . "\xFF";   // genre

        $this->assertSame(128, strlen($tag));

        return $this->tempFile(str_repeat("\x00", 256) . $tag, 'id3v1.mp3');
    }

    private function tempFile(string $content, string $name = 'test.mp3'): string
    {
        $path = sys_get_temp_dir() . '/' . $name . '_' . uniqid();
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
