<?php

namespace App\Modules\Metadata\Readers;

use App\Modules\Metadata\Contracts\MetadataReaderInterface;
use App\Modules\Metadata\MediaMeta\MediaMeta;
use Illuminate\Support\Facades\Log;

/**
 * ID3 reader wrapper
 * Adapts existing MediaMeta class to MetadataReaderInterface
 * Provides backward compatibility with existing ID3 support
 */
class Id3Reader implements MetadataReaderInterface
{
    private const string LOG_TAG = 'Id3Reader ';
    private MediaMeta $mediaMeta;

    public function __construct(public string $filePath)
    {
        // Use existing MediaMeta class
        $this->mediaMeta = new MediaMeta($filePath);
    }

    public function getTitle(): ?string
    {
        return $this->mediaMeta->getTitle();
    }

    public function getArtist(): array|string|null
    {
        return $this->mediaMeta->getArtist();
    }

    public function getArtists(): array
    {
        return $this->mediaMeta->getArtists();
    }

    public function getAlbum(): ?string
    {
        return $this->mediaMeta->getAlbum();
    }

    public function getGenre(): ?string
    {
        return $this->mediaMeta->getGenre();
    }

    public function getYear(): ?string
    {
        return $this->mediaMeta->getYear();
    }

    public function getTrackNumber(): ?int
    {
        return $this->mediaMeta->getTrackNumber();
    }

    public function getDiscNumber(): ?int
    {
        // ID3 TPOS frame contains disc number
        // This would need to be added to MediaMeta
        return null; // TODO: Implement TPOS frame support
    }

    public function getComments(): array
    {
        return $this->mediaMeta->getComments();
    }

    public function getComment(): ?string
    {
        return $this->mediaMeta->getComment();
    }

    public function getImages(): array
    {
        return $this->mediaMeta->getImages();
    }

    public function getFrontCoverImage(): ?object
    {
        return $this->mediaMeta->getFrontCoverImage();
    }

    public function getFormat(): string
    {
        return 'id3';
    }

    public function isValid(): bool
    {
        return $this->mediaMeta->isAudioFile();
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get the underlying MediaMeta instance
     * Useful for accessing ID3-specific features
     */
    public function getMediaMeta(): MediaMeta
    {
        return $this->mediaMeta;
    }
}
