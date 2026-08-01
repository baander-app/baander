<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Converter;

use App\Media\Infrastructure\Converter\GdImageConverter;
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

        $converter = new GdImageConverter();
        $output = $converter->convertToWebp($source, $this->tmpDir);

        $this->assertSame($this->tmpDir . '/source.webp', $output);
        $this->assertFileExists($output);
        $this->assertNoTempFilesRemain();
    }

    public function testConvertPresetUsesAtomicRenameAndLeavesNoTempFiles(): void
    {
        $source = $this->createPng($this->tmpDir . '/source.png', 2000, 1500);

        $converter = new GdImageConverter();
        $output = $converter->convertPreset($source, $this->tmpDir, 'small');

        $this->assertSame($this->tmpDir . '/source_small.webp', $output);
        $this->assertFileExists($output);
        $this->assertNoTempFilesRemain();
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
