<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Infrastructure\Reader\FlacReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlacReaderTest extends TestCase
{
    private FlacReader $reader;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->reader = new FlacReader($this->logger);
    }

    public function testReadExtractsBasicTags(): void
    {
        $file = $this->buildFlacFile([
            'TITLE' => ['Song Title'],
            'ARTIST' => ['Song Artist'],
            'ALBUM' => ['Song Album'],
            'ALBUMARTIST' => ['VA'],
            'COMPOSER' => ['Composer'],
            'GENRE' => ['Rock'],
            'TRACKNUMBER' => ['3'],
            'DISCNUMBER' => ['1'],
            'DATE' => ['2024'],
            'BPM' => ['120'],
            'DESCRIPTION' => ['A note'],
            'MUSICBRAINZ_TRACKID' => ['mb-track-1'],
            'MUSICBRAINZ_ALBUMID' => ['mb-album-1'],
            'MUSICBRAINZ_ARTISTID' => ['mb-artist-1'],
        ]);

        $metadata = $this->reader->read($file);

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertSame('Song Title', $metadata->getTitle());
        $this->assertSame('Song Artist', $metadata->getArtist());
        $this->assertSame('Song Album', $metadata->getAlbum());
        $this->assertSame('VA', $metadata->getAlbumArtist());
        $this->assertSame('Composer', $metadata->getComposer());
        $this->assertSame(['Rock'], $metadata->getGenre());
        $this->assertSame(3, $metadata->getTrackNumber());
        $this->assertSame(1, $metadata->getDiscNumber());
        $this->assertSame(2024, $metadata->getYear());
        $this->assertSame(120, $metadata->getBpm());
        $this->assertSame('A note', $metadata->getComment());
        $this->assertSame('mb-track-1', $metadata->getMbid());
        $this->assertSame('mb-album-1', $metadata->getMbAlbumId());
        $this->assertSame('mb-artist-1', $metadata->getMbArtistId());
    }

    public function testReadExtractsStreamInfo(): void
    {
        $file = $this->buildFlacFile(['TITLE' => ['Test']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(44100, $metadata->getSampleRate());
        $this->assertSame(2, $metadata->getChannels());
        // 441000 total samples / 44100 Hz = 10.0 seconds
        $this->assertEqualsWithDelta(10.0, $metadata->getDuration(), 0.001);
    }

    public function testReadExtractsPictures(): void
    {
        $file = $this->buildFlacFileWithPicture(['TITLE' => ['Art']]);

        $metadata = $this->reader->read($file);

        $pictures = $metadata->getPictures();
        $this->assertCount(1, $pictures);
        $this->assertInstanceOf(CoverArt::class, $pictures[0]);
        $this->assertSame(CoverArt::TYPE_COVER_FRONT, $pictures[0]->getType());
        $this->assertSame('image/jpeg', $pictures[0]->getMimeType());
        $this->assertSame('cover', $pictures[0]->getDescription());
    }

    public function testReadReturnsEmptyForNonExistentFile(): void
    {
        $metadata = $this->reader->read('/nonexistent/file.flac');

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertNull($metadata->getTitle());
    }

    public function testReadReturnsEmptyForInvalidFile(): void
    {
        $file = $this->tempFile('this is not a flac file');

        $metadata = $this->reader->read($file);

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertNull($metadata->getTitle());
    }

    public function testReadHandlesMultiValueGenre(): void
    {
        $file = $this->buildFlacFile(['GENRE' => ['Rock, Alternative']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(['Rock', 'Alternative'], $metadata->getGenre());
    }

    public function testReadHandlesTrackWithSlashFormat(): void
    {
        $file = $this->buildFlacFile(['TRACKNUMBER' => ['5/12']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(5, $metadata->getTrackNumber());
    }

    public function testReadHandlesYearFromDate(): void
    {
        $file = $this->buildFlacFile(['DATE' => ['2024-03-15']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(2024, $metadata->getYear());
    }

    public function testDurationTagDoesNotOverrideStreamInfo(): void
    {
        // StreamInfo says 10s, but DURATION tag says 999s
        $file = $this->buildFlacFile(['DURATION' => ['999'], 'TITLE' => ['Test']]);

        $metadata = $this->reader->read($file);

        // StreamInfo duration should win
        $this->assertEqualsWithDelta(10.0, $metadata->getDuration(), 0.001);
    }

    public function testDurationFromTagWhenNoStreamInfo(): void
    {
        // Build a FLAC file with zero total samples (no duration from stream info)
        $file = $this->buildFlacFileWithZeroSamples(['DURATION' => ['180.5'], 'TITLE' => ['Test']]);

        $metadata = $this->reader->read($file);

        $this->assertEqualsWithDelta(180.5, $metadata->getDuration(), 0.001);
    }

    // ---- Helpers (same as FlacParserTest) ----

    private function buildFlacFile(array $comments): string
    {
        $streamInfoBlock = $this->buildStreamInfoBlock(false);
        $commentBlock = $this->buildVorbisCommentBlock($comments, true);

        return $this->tempFile("fLaC" . $streamInfoBlock . $commentBlock);
    }

    private function buildFlacFileWithPicture(array $comments): string
    {
        $streamInfoBlock = $this->buildStreamInfoBlock(false);
        $commentBlock = $this->buildVorbisCommentBlock($comments, false);
        $pictureBlock = $this->buildPictureBlock(true);

        return $this->tempFile("fLaC" . $streamInfoBlock . $commentBlock . $pictureBlock);
    }

    private function buildFlacFileWithZeroSamples(array $comments): string
    {
        // STREAMINFO with 0 total samples
        $data = pack('nn', 4096, 4096) . "\x00\x00\x00\x00\x00\x00"
              . pack('NN', 0x0AC442F0, 0)                     // 0 total samples
              . str_repeat("\x00", 16);

        $header = chr(0x00) . substr(pack('N', 34), 1);
        $streamInfoBlock = $header . $data;
        $commentBlock = $this->buildVorbisCommentBlock($comments, true);

        return $this->tempFile("fLaC" . $streamInfoBlock . $commentBlock);
    }

    private function buildStreamInfoBlock(bool $isLast): string
    {
        $data = pack('nn', 4096, 4096) . "\x00\x00\x00\x00\x00\x00"
              . pack('NN', 0x0AC442F0, 0x0006BAA8)
              . str_repeat("\x00", 16);

        return $this->buildBlockHeader(0, $data, $isLast) . $data;
    }

    private function buildVorbisCommentBlock(array $comments, bool $isLast): string
    {
        $vendor = 'Test';
        $totalEntries = array_sum(array_map('count', $comments));
        $data = pack('V', strlen($vendor)) . $vendor . pack('V', $totalEntries);

        foreach ($comments as $key => $values) {
            foreach ($values as $value) {
                $entry = strtoupper($key) . '=' . $value;
                $data .= pack('V', strlen($entry)) . $entry;
            }
        }

        return $this->buildBlockHeader(4, $data, $isLast) . $data;
    }

    private function buildPictureBlock(bool $isLast): string
    {
        $mimeType = 'image/jpeg';
        $description = 'cover';
        $imageData = "\xFF\xD8\xFF\xE0";

        $data = pack('N', 3)
             . pack('N', strlen($mimeType)) . $mimeType
             . pack('N', strlen($description)) . $description
             . pack('NNNN', 100, 100, 24, 0)
             . pack('N', strlen($imageData)) . $imageData;

        return $this->buildBlockHeader(6, $data, $isLast) . $data;
    }

    private function buildBlockHeader(int $type, string $data, bool $isLast): string
    {
        $firstByte = ($isLast ? 0x80 : 0x00) | ($type & 0x7F);

        return chr($firstByte) . substr(pack('N', strlen($data)), 1);
    }

    private function tempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/flac_' . uniqid() . '.flac';
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/flac_*.flac') as $file) {
            @unlink($file);
        }
    }
}
