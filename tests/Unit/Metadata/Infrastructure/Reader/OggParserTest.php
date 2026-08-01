<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Infrastructure\Reader\OggParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OggParserTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testParsesVorbisComments(): void
    {
        $file = $this->buildOggFile([
            'TITLE' => ['Ogg Song'],
            'ARTIST' => ['Ogg Artist'],
            'ALBUM' => ['Ogg Album'],
        ]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertTrue($parser->hasVorbisComments());

        $comments = $parser->getVorbisCommentBlock();
        $this->assertNotNull($comments);
        $this->assertSame(['Ogg Song'], $comments['comments']['TITLE']);
        $this->assertSame(['Ogg Artist'], $comments['comments']['ARTIST']);
        $this->assertSame(['Ogg Album'], $comments['comments']['ALBUM']);
    }

    public function testParsesMultipleValuesForSameKey(): void
    {
        $file = $this->buildOggFile([
            'GENRE' => ['Electronic', 'Ambient'],
        ]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $comments = $parser->getVorbisCommentBlock();
        $this->assertSame(['Electronic', 'Ambient'], $comments['comments']['GENRE']);
    }

    public function testParsesMetadataBlockPicture(): void
    {
        // Build a METADATA_BLOCK_PICTURE payload, then base64-encode it
        $imageData = "\xFF\xD8\xFF\xE0";
        $picturePayload = pack('N', 3)                                  // type: cover front
                       . pack('N', strlen('image/jpeg')) . 'image/jpeg'  // MIME
                       . pack('N', strlen('cover')) . 'cover'            // description
                       . pack('NNNN', 200, 200, 24, 0)                  // dimensions
                       . pack('N', strlen($imageData)) . $imageData;    // image data

        $b64Picture = base64_encode($picturePayload);

        $file = $this->buildOggFile([
            'METADATA_BLOCK_PICTURE' => [$b64Picture],
        ]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $pictures = $parser->getPictures();
        $this->assertCount(1, $pictures);
        $this->assertSame(3, $pictures[0]['type']);
        $this->assertSame('image/jpeg', $pictures[0]['mimeType']);
        $this->assertSame($imageData, $pictures[0]['imageData']);
        $this->assertSame(200, $pictures[0]['width']);
    }

    public function testParsesLegacyCoverartField(): void
    {
        $jpegData = "\xFF\xD8\xFF\xE0\x00\x10JFIF";
        $b64CoverArt = base64_encode($jpegData);

        $file = $this->buildOggFile([
            'COVERART' => [$b64CoverArt],
        ]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $pictures = $parser->getPictures();
        $this->assertCount(1, $pictures);
        $this->assertSame(3, $pictures[0]['type']); // defaults to cover front
        $this->assertSame('image/jpeg', $pictures[0]['mimeType']);
        $this->assertSame($jpegData, $pictures[0]['imageData']);
    }

    public function testThrowsOnInvalidSignature(): void
    {
        $file = $this->tempFile("RIFF\x00\x00\x00\x00WAVE");

        $parser = new OggParser($file, $this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not an OGG file');
        $parser->parse();
    }

    public function testThrowsOnNonExistentFile(): void
    {
        $parser = new OggParser('/nonexistent/file.ogg', $this->logger);

        $this->expectException(\RuntimeException::class);
        $parser->parse();
    }

    public function testEmptyCommentsParsedSuccessfully(): void
    {
        $file = $this->buildOggFile([]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $comments = $parser->getVorbisCommentBlock();
        $this->assertNotNull($comments);
        $this->assertEmpty($comments['comments']);
    }

    public function testVendorStringPreserved(): void
    {
        $file = $this->buildOggFileWithVendor('Xiph.Org libVorbis I 20150105', ['TITLE' => ['Song']]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $this->assertSame('Xiph.Org libVorbis I 20150105', $parser->getVorbisCommentBlock()['vendor']);
    }

    public function testNoPicturesWhenNonePresent(): void
    {
        $file = $this->buildOggFile(['TITLE' => ['No Pics']]);

        $parser = new OggParser($file, $this->logger);
        $parser->parse();

        $this->assertSame([], $parser->getPictures());
    }

    // ---- Helpers ----

    /**
     * Build a minimal OGG file with a Vorbis comment header.
     *
     * @param array<string, list<string>> $comments
     */
    private function buildOggFile(array $comments): string
    {
        return $this->buildOggFileWithVendor('PHP Unit Test', $comments);
    }

    /**
     * @param array<string, list<string>> $comments
     */
    private function buildOggFileWithVendor(string $vendor, array $comments): string
    {
        // Build the Vorbis comment packet
        $commentPacket = "\x03vorbis"
                       . pack('V', strlen($vendor)) . $vendor
                       . pack('V', array_sum(array_map('count', $comments)));

        foreach ($comments as $key => $values) {
            foreach ($values as $value) {
                $entry = strtoupper($key) . '=' . $value;
                $commentPacket .= pack('V', strlen($entry)) . $entry;
            }
        }

        // Build segment table for the comment packet
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

        // Build OGG page header (27 bytes)
        $pageHeader = "OggS"               // capture pattern (4)
                   . "\x00"                 // version (1)
                   . "\x02"                 // header type: BOS (1)
                   . "\x00\x00\x00\x00\x00\x00\x00\x00" // granule position (8)
                   . pack('V', 1)           // serial number (4)
                   . pack('V', 0)           // page sequence number (4)
                   . pack('V', 0)           // CRC checksum (4, dummy)
                   . chr(count($segments)); // number of segments (1)

        $content = $pageHeader . $segmentTable . $commentPacket;

        return $this->tempFile($content);
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
