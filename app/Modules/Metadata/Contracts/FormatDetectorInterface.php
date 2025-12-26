<?php

namespace App\Modules\Metadata\Contracts;

/**
 * Format detection interface
 * Uses magic bytes for reliable file format detection
 */
interface FormatDetectorInterface
{
    /**
     * Detect audio file format from magic bytes
     *
     * @param string $filePath Absolute path to file
     * @return string Format identifier ('flac', 'id3', 'ogg', 'mp4', 'unknown')
     */
    public function detect(string $filePath): string;

    /**
     * Check if file is FLAC format
     *
     * @param string $filePath
     * @return bool
     */
    public function isFlac(string $filePath): bool;

    /**
     * Check if file has ID3 tags (MP3)
     *
     * @param string $filePath
     * @return bool
     */
    public function isId3(string $filePath): bool;
}
