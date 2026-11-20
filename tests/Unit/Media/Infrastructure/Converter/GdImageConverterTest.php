<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Converter;

use App\Media\Infrastructure\Converter\GdImageConverter;
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

    public function testConvertToWebpUsesAtomicRenameAndLeavesNoTempFiles(): void
    {
        $source = $this->createPng($this->tmpDir . '/source.png', 100, 100);

        $converter = new GdImageConverter(new ImageProcessor());
        $output = $converter->convertToWebp($source, $this->tmpDir);

        $this->assertSame($this->tmpDir . '/source.webp', $output);
        $this->assertFileExists($output);
        $this->assertSame('image/webp', getimagesize($output)['mime']);
        $this->assertSame([100, 100], array_slice(getimagesize($output), 0, 2));
        $this->assertNoTempFilesRemain();
    }

    public function testConvertPresetUsesAtomicRenameAndLeavesNoTempFiles(): void
    {
        $source = $this->createPng($this->tmpDir . '/source.png', 2000, 1500);

        $converter = new GdImageConverter(new ImageProcessor());
        $output = $converter->convertPreset($source, $this->tmpDir, 'small');

        $this->assertSame($this->tmpDir . '/source_small.webp', $output);
        $this->assertFileExists($output);
        $this->assertSame('image/webp', getimagesize($output)['mime']);
        $this->assertSame([300, 225], array_slice(getimagesize($output), 0, 2));
        $this->assertNoTempFilesRemain();
    }

    public function testSmallSourceStillPublishesPresetWithoutUpscaling(): void
    {
        $source = $this->createPng($this->tmpDir . '/small.png', 32, 16);
        $converter = new GdImageConverter(new ImageProcessor());

        $output = $converter->convertPreset($source, $this->tmpDir, 'thumb');

        $this->assertSame($this->tmpDir . '/small_thumb.webp', $output);
        $this->assertSame([32, 16], array_slice(getimagesize($output), 0, 2));
        $this->assertNoTempFilesRemain();
    }

    public function testExistingWebpReturnsItsSourcePath(): void
    {
        $source = $this->createPng($this->tmpDir . '/source.png', 32, 16);
        $converter = new GdImageConverter(new ImageProcessor());
        $webp = $converter->convertToWebp($source, $this->tmpDir);

        $this->assertSame($webp, $converter->convertToWebp($webp, $this->tmpDir . '/unused'));
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/unused');
    }

    public function testUnknownPresetIsRejectedBeforeReadingTheSource(): void
    {
        $converter = new GdImageConverter(new ImageProcessor());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown preset "unknown".');

        $converter->convertPreset($this->tmpDir . '/missing.png', $this->tmpDir, 'unknown');
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

    private function createPng(string $path, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);

        $background = imagecolorallocate($image, 255, 0, 0);
        imagefill($image, 0, 0, $background);

        $this->assertTrue(imagepng($image, $path));

        return $path;
    }

    private function assertNoTempFilesRemain(): void
    {
        $this->assertSame([], (array) glob($this->tmpDir . '/*.tmp'));
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
