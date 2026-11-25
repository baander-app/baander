<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Tests\Parser;

use Baander\MediaMetadata\Parser\FlacParser;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlacParserTest extends TestCase
{
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    public function testParsesValidFlacWithStreamInfoAndComments(): void
    {
        $file = $this->buildFlacFile([
            'TITLE' => ['Test Song'],
            'ARTIST' => ['Test Artist'],
            'ALBUM' => ['Test Album'],
        ]);

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertNotNull($parser->getStreamInfo());
        $this->assertSame(44100, $parser->getStreamInfo()['sampleRate']);
        $this->assertSame(2, $parser->getStreamInfo()['channels']);
        $this->assertSame(16, $parser->getStreamInfo()['bitsPerSample']);
        $this->assertSame(441000, $parser->getStreamInfo()['totalSamples']);

        $comments = $parser->getVorbisCommentBlock();
        $this->assertNotNull($comments);
        $this->assertSame(['Test Song'], $comments['comments']['TITLE']);
        $this->assertSame(['Test Artist'], $comments['comments']['ARTIST']);
        $this->assertSame(['Test Album'], $comments['comments']['ALBUM']);
    }

    public function testParsesMultipleValuesForSameKey(): void
    {
        $file = $this->buildFlacFile([
            'GENRE' => ['Rock', 'Alternative'],
            'ARTIST' => ['Artist One', 'Artist Two'],
        ]);

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $comments = $parser->getVorbisCommentBlock();
        $this->assertSame(['Rock', 'Alternative'], $comments['comments']['GENRE']);
        $this->assertSame(['Artist One', 'Artist Two'], $comments['comments']['ARTIST']);
    }

    public function testParsesPictureBlock(): void
    {
        $file = $this->buildFlacFileWithPicture([
            'TITLE' => ['Song With Art'],
        ]);

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $pictures = $parser->getPictureBlocks();
        $this->assertCount(1, $pictures);
        $this->assertSame(3, $pictures[0]['type']);
        $this->assertSame('image/jpeg', $pictures[0]['mimeType']);
        $this->assertSame('cover', $pictures[0]['description']);
        $this->assertSame("\xFF\xD8\xFF\xE0", $pictures[0]['imageData']);
        $this->assertSame(4, $pictures[0]['imageSize']);
        $this->assertSame(100, $pictures[0]['width']);
        $this->assertSame(100, $pictures[0]['height']);
    }

    public function testParsesMetadataBlockList(): void
    {
        $file = $this->buildFlacFile(['TITLE' => ['Test']]);

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $blocks = $parser->getMetadataBlocks();
        $this->assertCount(2, $blocks); // STREAMINFO + VORBIS_COMMENT
        $this->assertSame('STREAMINFO', $blocks[0]['type']);
        $this->assertSame('VORBIS_COMMENT', $blocks[1]['type']);
        $this->assertTrue($blocks[1]['isLast']);
    }

    /**
     * Three 12 MiB pictures exceed the parser's 32 MiB budget: the first two are read, the
     * third is skipped unread, and the tags before them survive.
     */
    public function testMetadataBlocksBeyondTheByteBudgetAreSkipped(): void
    {
        $picture = $this->buildPictureBlock(false, str_repeat("\xFF", 12 * 1024 * 1024));
        $file = $this->tempFile(
            "fLaC"
            . $this->buildStreamInfoBlock(false)
            . $this->buildVorbisCommentBlock(['TITLE' => ['Big Art']], false)
            . $picture . $picture . $picture
            . $this->buildBlockHeader(1, "\0\0\0\0", true) . "\0\0\0\0",
        );

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertSame(['Big Art'], $parser->getVorbisCommentBlock()['comments']['TITLE'] ?? null);
        $this->assertCount(2, $parser->getPictureBlocks());
        $this->assertSame(
            ['STREAMINFO', 'VORBIS_COMMENT', 'PICTURE', 'PICTURE', 'PICTURE', 'PADDING'],
            array_column($parser->getMetadataBlocks(), 'type'),
        );
        $this->assertArrayNotHasKey('data', $parser->getMetadataBlocks()[0], 'Raw block data is not kept.');
    }

    /** Empty blocks are valid; past the block limit the parser stops and keeps what it read. */
    public function testAFloodOfEmptyBlocksParsesToABoundedList(): void
    {
        $file = $this->tempFile(
            "fLaC"
            . $this->buildStreamInfoBlock(false)
            . $this->buildVorbisCommentBlock(['TITLE' => ['Flooded']], false)
            . str_repeat($this->buildBlockHeader(1, '', false), 5_000)
            . $this->buildBlockHeader(1, '', true),
        );

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $this->assertSame(['Flooded'], $parser->getVorbisCommentBlock()['comments']['TITLE'] ?? null);
        $this->assertCount(FlacParser::MAX_BLOCKS, $parser->getMetadataBlocks());
    }

    /** Each comment or seek point costs about twenty times its bytes in memory, so a block keeps at most the entry limit. */
    public function testACommentBlockAndASeekTableKeepAtMostTheEntryLimit(): void
    {
        $points = '';
        for ($i = 0; $i < 70_000; ++$i) {
            $points .= pack('JJn', $i * 4096, $i * 1000, 4096);
        }
        $file = $this->tempFile(
            "fLaC"
            . $this->buildStreamInfoBlock(false)
            . $this->buildVorbisCommentBlock(['LYRICS' => array_map(strval(...), range(1, 70_000))], false)
            . $this->buildBlockHeader(3, $points, true) . $points,
        );

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $this->assertCount(FlacParser::MAX_BLOCK_ENTRIES, $parser->getVorbisCommentBlock()['comments']['LYRICS'] ?? []);
        $this->assertSame(FlacParser::MAX_BLOCK_ENTRIES, $parser->getSeektableBlock()['pointCount'] ?? null);
    }

    public function testThrowsOnInvalidSignature(): void
    {
        $file = $this->tempFile("RIFF\x00\x00\x00\x00WAVE");

        $parser = new FlacParser($file, $this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid FLAC signature');

        $parser->parse();
    }

    public function testThrowsOnNonExistentFile(): void
    {
        $parser = new FlacParser('/nonexistent/file.flac', $this->logger);

        $this->expectException(\RuntimeException::class);
        $parser->parse();
    }

    public function testHasVorbisComments(): void
    {
        $file = $this->buildFlacFile(['TITLE' => ['Song']]);

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->hasVorbisComments());
    }

    public function testEmptyCommentsParsedSuccessfully(): void
    {
        $file = $this->buildFlacFile([]);

        $parser = new FlacParser($file, $this->logger);
        $parser->parse();

        $this->assertTrue($parser->isValid());
        $comments = $parser->getVorbisCommentBlock();
        $this->assertNotNull($comments);
        $this->assertEmpty($comments['comments']);
    }

    public function testParsePictureBlockStatic(): void
    {
        $mimeType = 'image/png';
        $description = 'test pic';
        $imageData = "\x89PNG\r\n\x1A\n";

        $data = pack('N', 3)
             . pack('N', strlen($mimeType)) . $mimeType
             . pack('N', strlen($description)) . $description
             . pack('NNNN', 200, 200, 32, 0)
             . pack('N', strlen($imageData)) . $imageData;

        $result = FlacParser::parsePictureBlock($data);

        $this->assertSame(3, $result['type']);
        $this->assertSame('image/png', $result['mimeType']);
        $this->assertSame('test pic', $result['description']);
        $this->assertSame($imageData, $result['imageData']);
        $this->assertSame(strlen($imageData), $result['imageSize']);
        $this->assertSame(200, $result['width']);
    }

    public function testParsePictureBlockReturnsEmptyOnTruncatedData(): void
    {
        $this->assertSame([], FlacParser::parsePictureBlock("\x00\x00"));
    }

    // ---- Helpers ----

    /** @param array<string, list<string>> $comments */
    private function buildFlacFile(array $comments): string
    {
        return $this->tempFile(
            "fLaC" . $this->buildStreamInfoBlock(false) . $this->buildVorbisCommentBlock($comments, true),
        );
    }

    /** @param array<string, list<string>> $comments */
    private function buildFlacFileWithPicture(array $comments): string
    {
        return $this->tempFile(
            "fLaC"
            . $this->buildStreamInfoBlock(false)
            . $this->buildVorbisCommentBlock($comments, false)
            . $this->buildPictureBlock(true),
        );
    }

    private function buildStreamInfoBlock(bool $isLast): string
    {
        // 44100 Hz, 2 channels, 16-bit, 441000 total samples (10.0s exactly)
        $data = pack('nn', 4096, 4096) . "\x00\x00\x00\x00\x00\x00"
              . pack('NN', 0x0AC442F0, 0x0006BAA8)
              . str_repeat("\x00", 16);

        $this->assertSame(34, strlen($data));

        return $this->buildBlockHeader(0, $data, $isLast) . $data;
    }

    /**
     * @param array<string, list<string>> $comments
     */
    /** @param array<string, list<string>> $comments */
    private function buildVorbisCommentBlock(array $comments, bool $isLast): string
    {
        $vendor = 'PHP Unit Test';
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

    private function buildPictureBlock(bool $isLast, string $imageData = "\xFF\xD8\xFF\xE0"): string
    {
        $mimeType = 'image/jpeg';
        $description = 'cover';

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
