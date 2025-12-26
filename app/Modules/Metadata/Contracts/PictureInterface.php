<?php

namespace App\Modules\Metadata\Contracts;

/**
 * Interface for picture/metadata image objects
 * Implemented by FlacPicture, Id3Picture, and other format-specific picture classes
 */
interface PictureInterface
{
    /**
     * Get the image type (cover front, artist, etc.)
     * Uses standard ID3 APIC / FLAC METADATA_BLOCK_PICTURE type codes (0-20)
     */
    public function getImageType(): int;

    /**
     * Get the MIME type (image/jpeg, image/png, etc.)
     */
    public function getMimeType(): string;

    /**
     * Get the picture description
     */
    public function getDescription(): string;

    /**
     * Get the binary image data
     */
    public function getImageData(): string;

    /**
     * Get the size of the image data in bytes
     */
    public function getImageSize(): int;

    /**
     * Get the image width in pixels (0 if unknown)
     */
    public function getWidth(): int;

    /**
     * Get the image height in pixels (0 if unknown)
     */
    public function getHeight(): int;

    /**
     * Get the color depth in bits (0 if unknown)
     */
    public function getColorDepth(): int;

    /**
     * Get the color count (0 if unknown/not applicable)
     */
    public function getColorCount(): int;

    /**
     * Get human-readable type name
     */
    public function getTypeName(): string;

    /**
     * Get data URI for use in <img> tags or CSS
     * Returns format: data:{mimetype};base64,{data}
     */
    public function getDataUri(): string;
}
