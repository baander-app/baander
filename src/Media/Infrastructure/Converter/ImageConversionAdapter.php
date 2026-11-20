<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Converter;

use App\Media\Application\Port\ImageConversionPortInterface;
use Baander\ImageProcessing\GdImageConverter as ImageProcessor;

final class ImageConversionAdapter implements ImageConversionPortInterface
{
    public const array PRESETS = [
        'thumb' => 150,
        'small' => 300,
        'medium' => 600,
        'large' => 1200,
    ];

    public function __construct(
        private readonly ImageProcessor $imageProcessor,
    ) {
    }

    public function convertToWebp(string $sourcePath, string $outputDirectory): string
    {
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);

        return $this->imageProcessor->convertToWebp($sourcePath, $outputDirectory . '/' . $filename . '.webp');
    }

    public function convertPreset(string $sourcePath, string $outputDirectory, string $preset): string
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new \InvalidArgumentException(sprintf('Unknown preset "%s".', $preset));
        }

        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $outputPath = $outputDirectory . '/' . $filename . '_' . $preset . '.webp';

        return $this->imageProcessor->resizeToWebp($sourcePath, $outputPath, self::PRESETS[$preset]);
    }
}
