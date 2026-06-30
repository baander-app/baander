<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Converter;

use App\Media\Application\Port\ImageConversionPortInterface;

final class GdImageConverter implements ImageConversionPortInterface
{
    public const array PRESETS = [
        'thumb' => 150,
        'small' => 300,
        'medium' => 600,
        'large' => 1200,
    ];

    private const int WEBP_QUALITY = 80;



    public function convertToWebp(string $sourcePath, string $outputDirectory): string
    {
        $imageInfo = getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \RuntimeException(sprintf('Failed to read image dimensions for "%s".', $sourcePath));
        }

        // Already WebP — no conversion needed
        if ($imageInfo[2] === IMAGETYPE_WEBP) {
            return $sourcePath;
        }

        $source = $this->createImage($sourcePath, $imageInfo[2]);
        if ($source === false) {
            throw new \RuntimeException(sprintf('Failed to load image "%s".', $sourcePath));
        }

        $this->ensureDirectory($outputDirectory);

        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $outputPath = $outputDirectory . '/' . $filename . '.webp';

        if (!imagewebp($source, $outputPath, self::WEBP_QUALITY)) {
            imagedestroy($source);
            throw new \RuntimeException(sprintf('Failed to write WebP to "%s".', $outputPath));
        }
        imagedestroy($source);

        return $outputPath;
    }

    public function convertPreset(string $sourcePath, string $outputDirectory, string $preset): string
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new \InvalidArgumentException(sprintf('Unknown preset "%s".', $preset));
        }

        $maxWidth = self::PRESETS[$preset];

        $imageInfo = getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \RuntimeException(sprintf('Failed to read image dimensions for "%s".', $sourcePath));
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];

        // Only downscale, never upscale
        if ($originalWidth <= $maxWidth) {
            return $sourcePath;
        }

        $ratio = $maxWidth / $originalWidth;
        $newHeight = (int) round($originalHeight * $ratio);

        $source = $this->createImage($sourcePath, $imageInfo[2]);
        if ($source === false) {
            throw new \RuntimeException(sprintf('Failed to load image "%s".', $sourcePath));
        }

        $canvas = imagecreatetruecolor($maxWidth, $newHeight);
        if ($canvas === false) {
            imagedestroy($source);
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

        $this->ensureDirectory($outputDirectory);

        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $outputPath = $outputDirectory . '/' . $filename . '_' . $preset . '.webp';

        if (!imagewebp($canvas, $outputPath, self::WEBP_QUALITY)) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new \RuntimeException(sprintf('Failed to write WebP preset to "%s".', $outputPath));
        }

        imagedestroy($source);
        imagedestroy($canvas);

        return $outputPath;
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
