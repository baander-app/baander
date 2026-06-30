<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Infrastructure\Reader\OggReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OggReaderTest extends TestCase
{
    private OggReader $reader;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->reader = new OggReader($this->logger);
    }

    public function testReadExtractsBasicTags(): void
    {
        $file = $this->buildOggFile([
            'TITLE' => ['Ogg Title'],
            'ARTIST' => ['Ogg Artist'],
            'ALBUM' => ['Ogg Album'],
            'ALBUMARTIST' => ['VA'],
            'COMPOSER' => ['Writer'],
            'GENRE' => ['Jazz'],
            'TRACKNUMBER' => ['4'],
            'DISCNUMBER' => ['2'],
            'DATE' => ['2024'],
            'BPM' => ['140'],
            'DESCRIPTION' => ['ogg note'],
            'DURATION' => ['200.5'],
            'MUSICBRAINZ_TRACKID' => ['mb-track'],
            'MUSICBRAINZ_ALBUMID' => ['mb-album'],
            'MUSICBRAINZ_ARTISTID' => ['mb-artist'],
        ]);

        $metadata = $this->reader->read($file);

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertSame('Ogg Title', $metadata->getTitle());
        $this->assertSame('Ogg Artist', $metadata->getArtist());
        $this->assertSame('Ogg Album', $metadata->getAlbum());
        $this->assertSame('VA', $metadata->getAlbumArtist());
        $this->assertSame('Writer', $metadata->getComposer());
        $this->assertSame(['Jazz'], $metadata->getGenre());
        $this->assertSame(4, $metadata->getTrackNumber());
        $this->assertSame(2, $metadata->getDiscNumber());
        $this->assertSame(2024, $metadata->getYear());
        $this->assertSame(140, $metadata->getBpm());
        $this->assertSame('ogg note', $metadata->getComment());
        $this->assertEqualsWithDelta(200.5, $metadata->getDuration(), 0.001);
        $this->assertSame('mb-track', $metadata->getMbid());
        $this->assertSame('mb-album', $metadata->getMbAlbumId());
        $this->assertSame('mb-artist', $metadata->getMbArtistId());
    }

    public function testReadExtractsMetadataBlockPicture(): void
    {
        $imageData = "\xFF\xD8\xFF\xE0";
        $picturePayload = pack('N', 3)
                       . pack('N', strlen('image/jpeg')) . 'image/jpeg'
                       . pack('N', strlen('cover')) . 'cover'
                       . pack('NNNN', 100, 100, 24, 0)
                       . pack('N', strlen($imageData)) . $imageData;

        $file = $this->buildOggFile([
            'TITLE' => ['Pic Song'],
            'METADATA_BLOCK_PICTURE' => [base64_encode($picturePayload)],
        ]);

        $metadata = $this->reader->read($file);

        $pictures = $metadata->getPictures();
        $this->assertCount(1, $pictures);
        $this->assertInstanceOf(CoverArt::class, $pictures[0]);
        $this->assertSame(3, $pictures[0]->getType());
        $this->assertSame('image/jpeg', $pictures[0]->getMimeType());
        $this->assertSame($imageData, $pictures[0]->getImageData());
    }

    public function testReadReturnsEmptyForNonExistentFile(): void
    {
        $metadata = $this->reader->read('/nonexistent/file.ogg');

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertNull($metadata->getTitle());
    }

    public function testReadReturnsEmptyForInvalidFile(): void
    {
        $file = $this->tempFile('not ogg at all');

        $metadata = $this->reader->read($file);

        $this->assertInstanceOf(ExtractedMetadata::class, $metadata);
        $this->assertNull($metadata->getTitle());
    }

    public function testReadHandlesMultiValueGenre(): void
    {
        $file = $this->buildOggFile(['GENRE' => ['Rock, Pop']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(['Rock', 'Pop'], $metadata->getGenre());
    }

    public function testReadHandlesTrackWithSlashFormat(): void
    {
        $file = $this->buildOggFile(['TRACKNUMBER' => ['5/12']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(5, $metadata->getTrackNumber());
    }

    public function testReadHandlesYearFromDate(): void
    {
        $file = $this->buildOggFile(['DATE' => ['2023-06-15']]);

        $metadata = $this->reader->read($file);

        $this->assertSame(2023, $metadata->getYear());
    }

    public function testReadUsesCommentAsDescription(): void
    {
        $file = $this->buildOggFile([
            'DESCRIPTION' => ['desc text'],
            'COMMENT' => ['comment text'],
        ]);

        $metadata = $this->reader->read($file);

        // DESCRIPTION takes precedence over COMMENT
        $this->assertSame('desc text', $metadata->getComment());
    }

    public function testReadFallsBackToComment(): void
    {
        $file = $this->buildOggFile([
            'COMMENT' => ['fallback comment'],
        ]);

        $metadata = $this->reader->read($file);

        $this->assertSame('fallback comment', $metadata->getComment());
    }

    // ---- Helpers ----

    private function buildOggFile(array $comments): string
    {
        $vendor = 'Test';
        $commentPacket = "\x03vorbis"
                       . pack('V', strlen($vendor)) . $vendor
                       . pack('V', array_sum(array_map('count', $comments)));

        foreach ($comments as $key => $values) {
            foreach ($values as $value) {
                $entry = strtoupper($key) . '=' . $value;
                $commentPacket .= pack('V', strlen($entry)) . $entry;
            }
        }

        $packetLen = strlen($commentPacket);
        $segments = [];
        $remaining = $packetLen;

        while ($remaining >= 255) {
            $segments[] = 255;
            $remaining -= 255;
        }
        $segments[] = $remaining;

        $segmentTable = '';
        foreach ($segments as $seg) {
            $segmentTable .= chr($seg);
        }

        $pageHeader = "OggS"
                   . "\x00\x02"
                   . "\x00\x00\x00\x00\x00\x00\x00\x00"
                   . pack('V', 1) . pack('V', 0) . pack('V', 0)
                   . chr(count($segments));

        return $this->tempFile($pageHeader . $segmentTable . $commentPacket);
    }

    private function tempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ogg_' . uniqid() . '.ogg';
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/ogg_*.ogg') as $file) {
            @unlink($file);
        }
    }
}
