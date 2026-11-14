<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Infrastructure\Reader\Id3Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Id3ParserTest extends TestCase
{
    private const string ARTIST_ID = '4d1e2a3b-5c6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const string OTHER_ARTIST_ID = '7f8e9d0c-1b2a-4c3d-9e4f-5a6b7c8d9e0f';

    /** @var list<string> */
    private array $temporaryFiles = [];

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
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

    public function testParsesId3v24FramesWithSyncsafeSizes(): void
    {
        // A frame whose data is >= 128 bytes proves the syncsafe decode: a 201-byte
        // frame size encodes as [0,0,1,73] syncsafe but reads as 329 when mis-decoded
        // big-endian — which overshoots and drops the following frame. Pre-fix, ARTIST
        // was never parsed and this assertion failed.
        $longTitle = str_repeat('A', 200);
        $frames = $this->buildSyncsafeFrame('TIT2', "\x00" . $longTitle)
               . $this->buildSyncsafeFrame('TPE1', "\x00Artist");

        $file = $this->buildId3v24File($frames);

        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertSame('ID3v2.4.0', $parser->getVersion());
        $this->assertSame([$longTitle], $parser->getTags()['TITLE']);
        $this->assertArrayHasKey('ARTIST', $parser->getTags(), 'ID3v2.4 must parse frames after a >=128-byte frame.');
        $this->assertSame(['Artist'], $parser->getTags()['ARTIST']);
    }

    public function testDropsTheTerminatorFromAnIso88591Value(): void
    {
        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TPE1', "\x00Suspekt\x00")));

        $this->assertSame(['Suspekt'], $parser->getTags()['ARTIST']);
    }

    public function testConvertsIso88591TextToUtf8(): void
    {
        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TIT2', "\x00K\xE6rlighed")));

        $this->assertSame(['Kærlighed'], $parser->getTags()['TITLE']);
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TPE1', "\x00  Bulldogg \x00")));

        $this->assertSame(['Bulldogg'], $parser->getTags()['ARTIST']);
    }

    public function testKeepsASlashInsideAnArtistValue(): void
    {
        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TPE1', "\x00AC/DC")));

        $this->assertSame(['AC/DC'], $parser->getTags()['ARTIST']);
    }

    public function testSplitsNulSeparatedValuesOfAnId3v24Frame(): void
    {
        $parser = $this->parse($this->buildId3v24File($this->buildSyncsafeFrame('TPE1', "\x03A\x00B\x00")));

        $this->assertSame(['A', 'B'], $parser->getTags()['ARTIST']);
    }

    public function testIgnoresTextAfterTheTerminatorOfAnId3v23Frame(): void
    {
        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TPE1', "\x00Suspekt\x00leftover")));

        $this->assertSame(['Suspekt'], $parser->getTags()['ARTIST']);
    }

    public function testDecodesEachUtf16ValueWithoutItsByteOrderMark(): void
    {
        $frame = "\x01" . $this->utf16('Headhunterz') . "\x00\x00" . $this->utf16('Sound Rush') . "\x00\x00";

        $parser = $this->parse($this->buildId3v24File($this->buildSyncsafeFrame('TPE1', $frame)));

        $this->assertSame(['Headhunterz', 'Sound Rush'], $parser->getTags()['ARTIST']);
    }

    public function testReadsTheMusicBrainzArtistIdFromItsUserTextFrame(): void
    {
        $frame = "\x00MusicBrainz Artist Id\x00" . self::ARTIST_ID;

        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TXXX', $frame)));

        $this->assertSame([self::ARTIST_ID], $parser->getTags()['MUSICBRAINZ_ARTISTID']);
    }

    public function testSplitsSlashJoinedMusicBrainzArtistIdsOfAnId3v23Tag(): void
    {
        $frame = "\x00MusicBrainz Artist Id\x00" . self::ARTIST_ID . '/' . self::OTHER_ARTIST_ID;

        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TXXX', $frame)));

        $this->assertSame([self::ARTIST_ID, self::OTHER_ARTIST_ID], $parser->getTags()['MUSICBRAINZ_ARTISTID']);
    }

    public function testReadsSlashJoinedMusicBrainzArtistIdsFromAUtf16UserTextFrame(): void
    {
        $frame = "\x01" . $this->utf16('MusicBrainz Artist Id') . "\x00\x00"
            . $this->utf16(self::ARTIST_ID . '/' . self::OTHER_ARTIST_ID) . "\x00\x00";

        $parser = $this->parse($this->buildId3v2File($this->buildFrame('TXXX', $frame)));

        $this->assertSame([self::ARTIST_ID, self::OTHER_ARTIST_ID], $parser->getTags()['MUSICBRAINZ_ARTISTID']);
    }

    public function testReadsEveryMusicBrainzAlbumArtistIdOfAnId3v24Frame(): void
    {
        $frame = "\x03MusicBrainz Album Artist Id\x00" . self::ARTIST_ID . "\x00" . self::OTHER_ARTIST_ID;

        $parser = $this->parse($this->buildId3v24File($this->buildSyncsafeFrame('TXXX', $frame)));

        $this->assertSame([self::ARTIST_ID, self::OTHER_ARTIST_ID], $parser->getTags()['MUSICBRAINZ_ALBUMARTISTID']);
    }

    public function testIgnoresUserTextFramesWithAnotherDescription(): void
    {
        $frames = $this->buildFrame('TXXX', "\x00MusicBrainz Album Id\x00" . self::ARTIST_ID)
            . $this->buildFrame('TXXX', "\x00replaygain_track_gain\x00-7.10 dB");

        $tags = $this->parse($this->buildId3v2File($frames))->getTags();

        $this->assertArrayNotHasKey('MUSICBRAINZ_ARTISTID', $tags);
        $this->assertArrayNotHasKey('MUSICBRAINZ_ALBUMARTISTID', $tags);
        $this->assertArrayNotHasKey('TXXX', $tags);
    }

    public function testReadsTheTextOfAUtf16CommentAfterItsDescription(): void
    {
        $frame = "\x01eng" . $this->utf16('A') . "\x00\x00" . $this->utf16('Liner note');

        $parser = $this->parse($this->buildId3v2File($this->buildFrame('COMM', $frame)));

        $this->assertSame(['Liner note'], $parser->getTags()['COMMENT']);
    }

    public function testKeepsPictureBytesIntactAfterAUtf16Description(): void
    {
        $imageData = "\xFF\xD8\xFF\xE0\x00\x10JFIF";
        $frame = "\x01image/jpeg\x00\x03" . $this->utf16('') . "\x00\x00" . $imageData;

        $pictures = $this->parse($this->buildId3v2File($this->buildFrame('APIC', $frame)))->getPictures();

        $this->assertCount(1, $pictures);
        $this->assertSame('', $pictures[0]['description']);
        $this->assertSame($imageData, $pictures[0]['imageData']);
    }

    // ---- Helpers ----

    private function parse(string $file): Id3Parser
    {
        $parser = new Id3Parser($file, $this->logger);
        $parser->parse();

        return $parser;
    }

    /** UTF-16 text with a little-endian byte-order mark, as Picard writes it. */
    private function utf16(string $text): string
    {
        return "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    }

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

    private function buildId3v24File(string $frameData): string
    {
        $size = strlen($frameData);
        // Header size is always syncsafe; major version byte is 4.
        $header = "ID3\x04\x00\x00"
            . chr(($size >> 21) & 0x7F)
            . chr(($size >> 14) & 0x7F)
            . chr(($size >> 7) & 0x7F)
            . chr($size & 0x7F);

        return $this->tempFile($header . $frameData, 'id3v24.mp3');
    }

    private function buildSyncsafeFrame(string $id, string $data): string
    {
        // ID3v2.4 frame size is syncsafe (7 bits/byte), unlike v2.3's big-endian.
        $size = strlen($data);

        return $id
            . chr(($size >> 21) & 0x7F)
            . chr(($size >> 14) & 0x7F)
            . chr(($size >> 7) & 0x7F)
            . chr($size & 0x7F)
            . "\x00\x00"
            . $data;
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
