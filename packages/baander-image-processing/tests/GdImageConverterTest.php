<?php

declare(strict_types=1);

namespace Baander\ImageProcessing\Tests;

use Baander\ImageProcessing\GdImageConverter as ImageProcessor;
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
        $this->assertSame([30, 15], array_slice(getimagesize($output), 0, 2));
        $decoded = imagecreatefromwebp($output);
        $this->assertNotFalse($decoded);
        $this->assertSame(127, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
        $this->assertSame([], (array) glob(dirname($destination) . '/*.tmp'));
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
