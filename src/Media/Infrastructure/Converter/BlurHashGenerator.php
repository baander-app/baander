<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Converter;

use Psr\Log\LoggerInterface;

/**
 * Generates BlurHash strings from image files.
 */
final class BlurHashGenerator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate a BlurHash for an image file.
     */
    public function generate(string $path, int $componentsX = 4, int $componentsY = 3): ?string
    {
        try {
            $imageInfo = getimagesize($path);
            if ($imageInfo === false) {
                return null;
            }

            $image = $this->loadImage($path, $imageInfo[2]);
            if ($image === false) {
                return null;
            }

            $hash = BlurHash::encode($image, $componentsX, $componentsY);
            imagedestroy($image);

            return $hash;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to generate BlurHash', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Decode a BlurHash to a GD image.
     */
    public function decode(string $blurHash, int $width, int $height): \GdImage
    {
        return BlurHash::decodeToGdImage($blurHash, $width, $height);
    }

    private function loadImage(string $path, int $type): \GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => false,
        };
    }
}
