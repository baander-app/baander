<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

interface ImageConversionPortInterface
{
    /**
     * Convert an image to WebP format (unconditional, no resize).
     * Returns the path to the converted WebP file.
     * If the source is already WebP, returns the source path unchanged.
     */
    public function convertToWebp(string $sourcePath, string $outputDirectory): string;

    /**
     * Convert an image to a specific preset size in WebP format.
     * Returns the path to the converted file.
     * If the source width <= maxWidth, returns the source path unchanged.
     */
    public function convertPreset(string $sourcePath, string $outputDirectory, string $preset): string;
}
