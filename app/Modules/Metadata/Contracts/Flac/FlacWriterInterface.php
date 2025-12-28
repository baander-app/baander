<?php

namespace App\Modules\Metadata\Contracts\Flac;

/**
 * FLAC metadata writer interface
 * Defines the contract for writing metadata to FLAC files
 */
interface FlacWriterInterface
{
    /**
     * Set a Vorbis comment field
     *
     * @param string $field The field name (e.g., "TITLE", "ARTIST")
     * @param string|array $value The value (string) or values (array for multiple values)
     * @return self
     */
    public function setField(string $field, string|array $value): self;

    /**
     * Set multiple Vorbis comment fields at once
     *
     * @param array<string, string|array> $fields Key-value pairs of field names and values
     * @return self
     */
    public function setFields(array $fields): self;

    /**
     * Remove a Vorbis comment field
     *
     * @param string $field The field name to remove
     * @return self
     */
    public function removeField(string $field): self;

    /**
     * Set the title
     *
     * @param string $title
     * @return self
     */
    public function setTitle(string $title): self;

    /**
     * Set the artist(s)
     *
     * @param string|array $artist Single artist as string, multiple artists as array
     * @return self
     */
    public function setArtist(string|array $artist): self;

    /**
     * Set the album
     *
     * @param string $album
     * @return self
     */
    public function setAlbum(string $album): self;

    /**
     * Set the genre
     *
     * @param string $genre
     * @return self
     */
    public function setGenre(string $genre): self;

    /**
     * Set the year/date
     *
     * @param string $year
     * @return self
     */
    public function setYear(string $year): self;

    /**
     * Set the track number
     *
     * @param int $track The track number
     * @param int|null $total The total number of tracks (optional)
     * @return self
     */
    public function setTrackNumber(int $track, ?int $total = null): self;

    /**
     * Set the disc number
     *
     * @param int $disc The disc number
     * @param int|null $total The total number of discs (optional)
     * @return self
     */
    public function setDiscNumber(int $disc, ?int $total = null): self;

    /**
     * Set a comment
     *
     * @param string $comment
     * @return self
     */
    public function setComment(string $comment): self;

    /**
     * Add or replace a picture
     *
     * @param object $picture FlacPicture instance or compatible picture object
     * @param int|null $type Optional picture type (defaults to picture's type)
     * @return self
     */
    public function setPicture(object $picture, ?int $type = null): self;

    /**
     * Remove all pictures
     *
     * @return self
     */
    public function clearPictures(): self;

    /**
     * Remove pictures of a specific type
     *
     * @param int $type The picture type to remove
     * @return self
     */
    public function removePicturesByType(int $type): self;

    /**
     * Write all changes to the file
     *
     * @param bool $backup Create a backup of the original file before writing
     * @return bool True on success
     * @throws \Exception If writing fails
     */
    public function write(bool $backup = true): bool;

    /**
     * Get the file path being written to
     *
     * @return string
     */
    public function getFilePath(): string;
}
