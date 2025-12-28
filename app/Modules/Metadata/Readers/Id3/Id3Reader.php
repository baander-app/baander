<?php

namespace App\Modules\Metadata\Readers\Id3;

use App\Modules\Metadata\Contracts\MetadataReaderInterface;
use App\Modules\Metadata\Contracts\PictureInterface;
use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use Illuminate\Support\Facades\Log;

/**
 * ID3 metadata reader
 * Simple implementation matching FlacReader architecture
 */
class Id3Reader implements MetadataReaderInterface
{
    private const string LOG_TAG = 'Id3Reader ';

    private string $filePath;
    private ?Id3Parser $parser = null;
    private array $tags = [];
    private array $pictures = [];

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->initialize();
    }

    private function initialize(): void
    {
        try {
            $this->parser = new Id3Parser($this->filePath);
            $this->parser->parse();

            // Cache tags for easy access
            $this->tags = $this->parser->getTags();

            // Convert picture arrays to Id3Picture objects
            foreach ($this->parser->getPictures() as $pictureData) {
                $this->pictures[] = Id3Picture::fromArray($pictureData);
            }

            Log::debug(self::LOG_TAG . 'ID3 reader initialized', [
                'file' => $this->filePath,
                'version' => $this->parser->getVersion(),
                'hasTags' => !empty($this->tags),
                'hasPictures' => count($this->pictures) > 0,
            ]);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Initialization failed', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw new InvalidFlacFileException(
                "Failed to parse ID3 file: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    // Standard metadata methods

    public function getTitle(): ?string
    {
        return $this->getFirstValue('TITLE');
    }

    public function getArtist(): array|string|null
    {
        $values = $this->getAllValues('ARTIST');

        if (empty($values)) {
            return null;
        }

        return count($values) === 1 ? $values[0] : $values;
    }

    public function getArtists(): array
    {
        return $this->getAllValues('ARTIST');
    }

    public function getAlbum(): ?string
    {
        return $this->getFirstValue('ALBUM');
    }

    public function getGenre(): ?string
    {
        return $this->getFirstValue('GENRE');
    }

    public function getYear(): ?string
    {
        return $this->getFirstValue('YEAR');
    }

    public function getTrackNumber(): ?int
    {
        $value = $this->getFirstValue('TRACKNUMBER');
        if ($value === null) {
            return null;
        }

        // Handle "track/total" format
        $track = preg_replace('/\/.*$/', '', $value);
        return is_numeric($track) ? (int)$track : null;
    }

    public function getDiscNumber(): ?int
    {
        $value = $this->getFirstValue('DISCNUMBER');
        if ($value === null) {
            return null;
        }

        // Handle "disc/total" format
        $disc = preg_replace('/\/.*$/', '', $value);
        return is_numeric($disc) ? (int)$disc : null;
    }

    public function getComments(): array
    {
        return $this->getAllValues('COMMENT');
    }

    public function getComment(): ?string
    {
        return $this->getFirstValue('COMMENT');
    }

    public function getImages(): array
    {
        return $this->pictures;
    }

    public function getFrontCoverImage(): ?PictureInterface
    {
        // Try to get cover front (type 3), fall back to first picture
        $pictures = $this->getPicturesByType(Id3Picture::IMAGE_COVER_FRONT);

        if (empty($pictures)) {
            $pictures = $this->pictures;
        }

        return $pictures[0] ?? null;
    }

    public function getFormat(): string
    {
        return 'id3';
    }

    public function isValid(): bool
    {
        return $this->parser !== null && $this->parser->isValid();
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    // ID3-specific methods

    /**
     * Get ID3 version detected
     */
    public function getVersion(): ?string
    {
        return $this->parser?->getVersion();
    }

    /**
     * Get all tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Get pictures by type
     */
    public function getPicturesByType(int $type): array
    {
        return array_filter($this->pictures, fn($p) => $p->getImageType() === $type);
    }

    /**
     * Get first picture
     */
    public function getFirstPicture(): ?object
    {
        return $this->pictures[0] ?? null;
    }

    // Helper methods

    private function getFirstValue(string $field): ?string
    {
        $values = $this->getAllValues($field);
        return $values[0] ?? null;
    }

    private function getAllValues(string $field): array
    {
        return $this->tags[$field] ?? [];
    }
}
