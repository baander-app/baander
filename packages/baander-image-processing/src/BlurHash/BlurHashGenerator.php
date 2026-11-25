<?php

declare(strict_types=1);

namespace Baander\ImageProcessing\BlurHash;

use Baander\ImageProcessing\ImageTooLargeException;
use Psr\Log\LoggerInterface;

/**
 * Generates BlurHash strings from image files.
 */
final class BlurHashGenerator
{
    /**
     * The longer edge of the copy a hash is computed from. BlurHash::encode() holds every pixel
     * it reads as a PHP array of three floats, about 390 bytes each, so a 1200 by 1200 cover read
     * whole takes 560 MB. The few low-frequency components a hash keeps come out close from an
     * area-averaged copy of this size (6 MB); at 64 pixels they drift visibly.
     */
    private const int SAMPLE_EDGE = 128;

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

            ImageTooLargeException::assertDecodable($imageInfo, $path);
            $image = $this->loadImage($path, $imageInfo[2]);
            if ($image === false) {
                return null;
            }

            return BlurHash::encode($this->sample($image), $componentsX, $componentsY);
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

    /** An area-averaged copy at most SAMPLE_EDGE pixels on its longer edge; smaller images are used as they are. */
    private function sample(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = self::SAMPLE_EDGE / max($width, $height);
        if ($scale >= 1) {
            return $image;
        }

        $sampleWidth = max(1, (int) round($width * $scale));
        $sampleHeight = max(1, (int) round($height * $scale));
        $sample = imagecreatetruecolor($sampleWidth, $sampleHeight);
        if ($sample === false) {
            throw new \RuntimeException('Failed to create the BlurHash sample.');
        }
        // Copy colour and alpha as they are, as encode() reads them from the source: blending
        // would darken transparent pixels against the black canvas.
        imagealphablending($sample, false);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $width, $height);

        return $sample;
    }
}
