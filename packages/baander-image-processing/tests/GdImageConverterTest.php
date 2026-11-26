<?php

declare(strict_types=1);

namespace Baander\ImageProcessing\Tests;

use Baander\ImageProcessing\GdImageConverter as ImageProcessor;
use Baander\ImageProcessing\ImageTooLargeException;
use PHPUnit\Framework\TestCase;

final class GdImageConverterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gd_converter_' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testProcessorUsesExplicitDestinationAndPreservesPngTransparencyWhenResizing(): void
    {
        $source = $this->tmpDir . '/transparent.png';
        $image = imagecreatetruecolor(80, 40);
        $this->assertNotFalse($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $this->assertNotFalse($transparent);
        imagefill($image, 0, 0, $transparent);
        $this->assertTrue(imagepng($image, $source));
        $destination = $this->tmpDir . '/nested/chosen.webp';

        $output = new ImageProcessor()->resizeToWebp($source, $destination, 30);

        $this->assertSame($destination, $output);
        $outputSize = getimagesize($output);
        $this->assertSame([30, 15], array_slice($outputSize, 0, 2));
        $decoded = imagecreatefromwebp($output);
        $this->assertNotFalse($decoded);
        $this->assertSame(127, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
        $this->assertSame([], (array) glob(dirname($destination) . '/*.tmp'));
    }

    public function testAnOrdinaryCoverConvertsAndResizes(): void
    {
        $source = $this->tmpDir . '/cover.jpg';
        $image = imagecreatetruecolor(1200, 1200);
        $this->assertNotFalse($image);
        $this->assertTrue(imagejpeg($image, $source));

        $converted = new ImageProcessor()->convertToWebp($source, $this->tmpDir . '/cover.webp');
        $resized = new ImageProcessor()->resizeToWebp($source, $this->tmpDir . '/cover_small.webp', 300);

        $convertedSize = getimagesize($converted);
        $this->assertSame([1200, 1200, IMAGETYPE_WEBP], array_slice($convertedSize, 0, 3));
        $resizedSize = getimagesize($resized);
        $this->assertSame([300, 300, IMAGETYPE_WEBP], array_slice($resizedSize, 0, 3));
    }

    /**
     * The header alone declares the size, so 66 bytes would make GD allocate a 3.6 GB bitmap.
     * Both conversions refuse before GD decodes anything.
     */
    public function testAnImageDeclaringThirtyThousandByThirtyThousandPixelsIsRefusedBeforeDecoding(): void
    {
        $source = $this->tmpDir . '/declared-huge.png';
        file_put_contents($source, self::pngDeclaring(30_000, 30_000));

        foreach ([
            fn () => new ImageProcessor()->convertToWebp($source, $this->tmpDir . '/huge.webp'),
            fn () => new ImageProcessor()->resizeToWebp($source, $this->tmpDir . '/huge_small.webp', 300),
        ] as $conversion) {
            try {
                $conversion();
                $this->fail('A 900-megapixel image must be refused.');
            } catch (ImageTooLargeException $exception) {
                $this->assertStringContainsString('30000 by 30000', $exception->getMessage());
            }
        }
        $this->assertSame([], (array) glob($this->tmpDir . '/*.webp*'));
    }

    /**
     * A 66-byte PNG whose header declares the size and whose data holds one byte. GD allocates
     * the whole bitmap from the header before it finds the data short.
     */
    public static function pngDeclaring(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress("\0"))
            . $chunk('IEND', '');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
