<?php

namespace App\Modules\Metadata\Contracts;

/**
 * Unified metadata reader interface
 * Provides consistent API across all audio formats
 */
interface MetadataReaderInterface
{
    /**
     * Get the title of the audio file
     *
     * @return string|null Title or null if not available
     */
    public function getTitle(): ?string;

    /**
     * Get the artist(s) of the audio file
     * Returns string for single artist, array for multiple
     *
     * @return array|string|null Artist(s) or null
     */
    public function getArtist(): array|string|null;

    /**
     * Get artists as array (always returns array)
     *
     * @return array<string> List of artists
     */
    public function getArtists(): array;

    /**
     * Get the album name
     *
     * @return string|null Album name or null
     */
    public function getAlbum(): ?string;

    /**
     * Get the genre
     *
     * @return string|null Genre or null
     */
    public function getGenre(): ?string;

    /**
     * Get the year
     *
     * @return string|null Year or null
     */
    public function getYear(): ?string;

    /**
     * Get the track number
     *
     * @return int|null Track number or null
     */
    public function getTrackNumber(): ?int;

    /**
     * Get the disc number
     *
     * @return int|null Disc number or null
     */
    public function getDiscNumber(): ?int;

    /**
     * Get all comments
     *
     * @return array Array of comment strings
     */
    public function getComments(): array;

    /**
     * Get the first comment
     *
     * @return string|null Comment text or null
     */
    public function getComment(): ?string;

    /**
     * Get all embedded images
     *
     * @return array<PictureInterface> List of image objects
     */
    public function getImages(): array;

    /**
     * Get the first/front cover image
     *
     * @return PictureInterface|null Cover image or null
     */
    public function getFrontCoverImage(): ?PictureInterface;

    /**
     * Get the audio file format
     *
     * @return string Format identifier ('flac', 'id3', 'ogg', etc.)
     */
    public function getFormat(): string;

    /**
     * Check if file is valid audio file
     *
     * @return bool True if valid audio file
     */
    public function isValid(): bool;

    /**
     * Get the file path
     *
     * @return string Absolute file path
     */
    public function getFilePath(): string;
}
