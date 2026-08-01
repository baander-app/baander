<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Converter;

use Psr\Log\LoggerInterface;

/**
 * Generates responsive image conversions using GD.
 *
 * @deprecated Use App\Media\Application\Port\ImageConversionPortInterface via GdImageConverter instead.
 *             This class will be removed in a future version.
 */
final class ImageConverter
{
    /**
     * Preset sizes for responsive image generation.
     *
     * @var array<string, int>
     */
    private const array PRESETS = [
        'thumb' => 150,
        'small' => 300,
        'medium' => 600,
        'large' => 1200,
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Convert an image to all preset sizes.
     *
     * @return array<string, string> Map of preset name to output path
     */
    public function convert(string $sourcePath, string $outputDirectory, string $format = 'webp'): array
    {
        $results = [];

        foreach (self::PRESETS as $preset => $maxWidth) {
            try {
                $outputPath = $this->convertSingle($sourcePath, $outputDirectory, $preset, $maxWidth, $format);
                $results[$preset] = $outputPath;
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to generate image conversion', [
                    'preset' => $preset,
                    'path' => $sourcePath,
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Convert an image to a specific size.
     */
    public function convertSingle(string $sourcePath, string $outputDirectory, string $preset, int $maxWidth, string $format = 'webp'): string
    {
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

        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0755, true);
        }

        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $outputPath = $outputDirectory . '/' . $filename . '_' . $preset . '.' . $format;

        $this->saveImage($canvas, $outputPath, $format);

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

    private function saveImage(\GdImage $image, string $path, string $format): void
    {
        match ($format) {
            'webp' => imagewebp($image, $path, 80),
            'jpg', 'jpeg' => imagejpeg($image, $path, 80),
            'png' => imagepng($image, $path, 8),
            default => imagejpeg($image, $path, 80),
        };
    }
}
