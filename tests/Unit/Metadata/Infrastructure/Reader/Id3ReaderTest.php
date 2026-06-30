<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Infrastructure\Reader\Id3Reader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Id3ReaderTest extends TestCase
{
    private Id3Reader $reader;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->reader = new Id3Reader($this->logger);
    }

    public function testReadExtractsBasicTags(): void
    {
        $frames = $this->buildFrame('TIT2', "\x00Song Title")
               . $this->buildFrame('TPE1', "\x00Song Artist")
               . $this->buildFrame('TALB', "\x00Song Album")
               . $this->buildFrame('TPE2', "\x00VA")
               . $this->buildFrame('TCOM', "\x00Songwriter")
               . $this->buildFrame('TCON', "\x00Rock")
               . $this->buildFrame('TRCK', "\x003")
               . $this->buildFrame('TPOS', "\x001")
               . $this->buildFrame('TYER', "\x002024")
               . $this->buildFrame('TBPM', "\x00120")
               . $this->buildFrame('COMM', "\x00eng\x00A note")
               . $this->buildFrame('TLEN', "\x00225000");

        $file = $this->buildId3v2File($frames);
        $metadata = $this->reader->read($file);

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertSame('Song Title', $metadata->getTitle());
        $this->assertSame('Song Artist', $metadata->getArtist());
        $this->assertSame('Song Album', $metadata->getAlbum());
        $this->assertSame('VA', $metadata->getAlbumArtist());
        $this->assertSame('Songwriter', $metadata->getComposer());
        $this->assertSame(['Rock'], $metadata->getGenre());
        $this->assertSame(3, $metadata->getTrackNumber());
        $this->assertSame(1, $metadata->getDiscNumber());
        $this->assertSame(2024, $metadata->getYear());
        $this->assertSame(120, $metadata->getBpm());
        $this->assertSame('A note', $metadata->getComment());
        // TLEN is in milliseconds: 225000 / 1000 = 225.0
        $this->assertEqualsWithDelta(225.0, $metadata->getDuration(), 0.001);
    }

    public function testReadExtractsPictures(): void
    {
        $imageData = "\xFF\xD8\xFF\xE0JFIF";
        $apicData = "\x00" . "image/jpeg\x00" . "\x03" . "cover\x00" . $imageData;

        $frames = $this->buildFrame('TIT2', "\x00Art Song")
               . $this->buildFrame('APIC', $apicData);

        $file = $this->buildId3v2File($frames);
        $metadata = $this->reader->read($file);

        $pictures = $metadata->getPictures();
        $this->assertCount(1, $pictures);
        $this->assertInstanceOf(CoverArt::class, $pictures[0]);
        $this->assertSame(3, $pictures[0]->getType());
        $this->assertSame('image/jpeg', $pictures[0]->getMimeType());
        $this->assertSame('cover', $pictures[0]->getDescription());
        $this->assertSame($imageData, $pictures[0]->getImageData());
    }

    public function testReadExtractsMultipleArtists(): void
    {
        // Reader takes first artist value
        $frames = $this->buildFrame('TPE1', "\x00Artist One")
               . $this->buildFrame('TPE1', "\x00Artist Two");

        $file = $this->buildId3v2File($frames);
        $metadata = $this->reader->read($file);

        $this->assertSame('Artist One', $metadata->getArtist());
    }

    public function testReadReturnsEmptyForNonExistentFile(): void
    {
        $metadata = $this->reader->read('/nonexistent/file.mp3');

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertNull($metadata->getTitle());
    }

    public function testReadReturnsEmptyForInvalidFile(): void
    {
        $file = $this->tempFile('not id3 at all');

        $metadata = $this->reader->read($file);

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertNull($metadata->getTitle());
    }

    public function testReadId3v1File(): void
    {
        $file = $this->buildId3v1File(
            title: 'Vintage Track',
            artist: 'Old Artist',
            album: 'Old Album',
            year: '1995',
        );

        $metadata = $this->reader->read($file);

        $this->assertSame('Vintage Track', $metadata->getTitle());
        $this->assertSame('Old Artist', $metadata->getArtist());
        $this->assertSame('Old Album', $metadata->getAlbum());
        $this->assertSame(1995, $metadata->getYear());
    }

    public function testReadHandlesYearWithExtraChars(): void
    {
        $frames = $this->buildFrame('TYER', "\x002024-05-10");

        $file = $this->buildId3v2File($frames);
        $metadata = $this->reader->read($file);

        $this->assertSame(2024, $metadata->getYear());
    }

    public function testReadHandlesTrackWithSlash(): void
    {
        $frames = $this->buildFrame('TRCK', "\x007/12");

        $file = $this->buildId3v2File($frames);
        $metadata = $this->reader->read($file);

        $this->assertSame(7, $metadata->getTrackNumber());
    }

    public function testReadHandlesTdrcYear(): void
    {
        $frames = $this->buildFrame('TDRC', "\x002023");

        $file = $this->buildId3v2File($frames);
        $metadata = $this->reader->read($file);

        $this->assertSame(2023, $metadata->getYear());
    }

    // ---- Helpers ----

    private function buildId3v2File(string $frameData): string
    {
        $size = strlen($frameData);
        $header = "ID3"
                . "\x03\x00"
                . "\x00"
                . chr(($size >> 21) & 0x7F)
                . chr(($size >> 14) & 0x7F)
                . chr(($size >> 7) & 0x7F)
                . chr(strlen($frameData) & 0x7F);

        return $this->tempFile($header . $frameData);
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
             . "\x00"
             . "\xFF";

        return $this->tempFile(str_repeat("\x00", 256) . $tag);
    }

    private function tempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/id3_' . uniqid() . '.mp3';
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/id3_*.mp3') as $file) {
            @unlink($file);
        }
    }
}
