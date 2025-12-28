<?php

namespace App\Modules\Metadata\Contracts\Flac;

use App\Modules\Metadata\Contracts\MetadataReaderInterface;

/**
 * FLAC-specific reader interface
 * Extends base interface with FLAC-specific features
 */
interface FlacReaderInterface extends MetadataReaderInterface
{
    /**
     * Get FLAC metadata blocks information
     *
     * @return array<int, array{type: string, isLast: bool, length: int}>
     */
    public function getMetadataBlocks(): array;

    /**
     * Get the seektable
     *
     * @return array|null Seektable or null if not present
     */
    public function getSeektable(): ?array;

    /**
     * Get audio stream information
     *
     * @return array{sampleRate: int, channels: int, bitsPerSample: int, totalSamples: int}|null
     */
    public function getStreamInfo(): ?array;

    /**
     * Get all Vorbis comments
     *
     * @return array<string, array<string>> Key => array of values
     */
    public function getVorbisComments(): array;

    /**
     * Get a specific Vorbis comment field
     *
     * @param string $field Field name (case-insensitive)
     * @return array<string> Array of values (empty if not found)
     */
    public function getVorbisCommentField(string $field): array;

    /**
     * Get all pictures (METADATA_BLOCK_PICTURE)
     *
     * @return array
     */
    public function getPictures(): array;

    /**
     * Get the first picture (typically front cover)
     *
     * @return object|null
     */
    public function getFirstPicture(): ?object;

    /**
     * Get picture by type
     *
     * @param int $type Picture type (same constants as ID3 APIC)
     * @return array
     */
    public function getPicturesByType(int $type): array;
}
