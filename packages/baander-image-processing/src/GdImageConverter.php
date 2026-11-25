<?php

declare(strict_types=1);

namespace Baander\ImageProcessing;

final class GdImageConverter
{
    private const int WEBP_QUALITY = 80;

    public function convertToWebp(string $sourcePath, string $outputPath): string
    {
        $imageInfo = getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \RuntimeException(sprintf('Failed to read image dimensions for "%s".', $sourcePath));
        }

        // Already WebP — no conversion needed
        if ($imageInfo[2] === IMAGETYPE_WEBP) {
            return $sourcePath;
        }

        $source = $this->decode($sourcePath, $imageInfo);

        $this->ensureDirectory(dirname($outputPath));
        $tempPath = $outputPath . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (!imagewebp($source, $tempPath, self::WEBP_QUALITY)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to write WebP to "%s".', $outputPath));
        }

        if (!rename($tempPath, $outputPath)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to atomically publish WebP to "%s".', $outputPath));
        }

        return $outputPath;
    }

    public function resizeToWebp(string $sourcePath, string $outputPath, int $maxWidth): string
    {
        $imageInfo = getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \RuntimeException(sprintf('Failed to read image dimensions for "%s".', $sourcePath));
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];

        // Preserve small source dimensions while still publishing the requested WebP.
        $maxWidth = min($maxWidth, $originalWidth);

        $ratio = $maxWidth / $originalWidth;
        $newHeight = (int) round($originalHeight * $ratio);

        $source = $this->decode($sourcePath, $imageInfo);

        $canvas = imagecreatetruecolor($maxWidth, $newHeight);
        if ($canvas === false) {
            throw new \RuntimeException('Failed to create canvas.');
        }

        // Preserve transparency for PNG
        if ($imageInfo[2] === IMAGETYPE_PNG) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefill($canvas, 0, 0, $transparent);
            }
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $maxWidth, $newHeight, $originalWidth, $originalHeight);

        $this->ensureDirectory(dirname($outputPath));
        $tempPath = $outputPath . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (!imagewebp($canvas, $tempPath, self::WEBP_QUALITY)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to write WebP preset to "%s".', $outputPath));
        }

        if (!rename($tempPath, $outputPath)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to atomically publish WebP preset to "%s".', $outputPath));
        }

        return $outputPath;
    }

    /**
     * @param array{0: int, 1: int, 2: int, ...} $imageInfo the getimagesize() result for $path
     *
     * @throws ImageTooLargeException when the header declares more pixels than GD may decode
     */
    private function decode(string $path, array $imageInfo): \GdImage
    {
        ImageTooLargeException::assertDecodable($imageInfo, $path);

        return $this->createImage($path, $imageInfo[2])
            ?: throw new \RuntimeException(sprintf('Failed to load image "%s".', $path));
    }

    private function createImage(string $path, int $type): \GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => false,
        };
    }

    private function ensureDirectory(string $outputDirectory): void
    {
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0755, true);
        }
    }
}
