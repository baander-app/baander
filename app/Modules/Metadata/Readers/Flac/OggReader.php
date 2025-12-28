<?php

namespace App\Modules\Metadata\Readers\Flac;

use App\Modules\Metadata\Contracts\MetadataReaderInterface;
use App\Modules\Metadata\Contracts\PictureInterface;
use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use Illuminate\Support\Facades\Log;

/**
 * OGG Vorbis metadata reader
 * Simple implementation matching FlacReader architecture
 * Uses Vorbis comments for metadata (same as FLAC)
 */
class OggReader implements MetadataReaderInterface
{
    private const string LOG_TAG = 'OggReader ';

    // Standard Vorbis comment field mapping to common metadata
    private const VORBIS_FIELD_MAP = [
        'TITLE' => 'getTitle',
        'ARTIST' => 'getArtist',
        'ALBUM' => 'getAlbum',
        'GENRE' => 'getGenre',
        'DATE' => 'getYear',
        'YEAR' => 'getYear',
        'TRACKNUMBER' => 'getTrackNumber',
        'TRACKTOTAL' => 'getTrackTotal',
        'DISCNUMBER' => 'getDiscNumber',
        'DISCTOTAL' => 'getDiscTotal',
        'COMMENT' => 'getComment',
        'DESCRIPTION' => 'getDescription',
    ];

    private string $filePath;
    private ?OggParser $parser = null;
    private array $vorbisComments = [];
    private array $pictures = [];

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->initialize();
    }

    private function initialize(): void
    {
        try {
            $this->parser = new OggParser($this->filePath);
            $this->parser->parse();

            // Cache Vorbis comments for easy access
            $commentBlock = $this->parser->getVorbisCommentBlock();
            if ($commentBlock) {
                $this->vorbisComments = $commentBlock['comments'];
            }

            // Convert picture data to FlacPicture objects (same format)
            foreach ($this->parser->getPictures() as $pictureData) {
                $this->pictures[] = FlacPicture::fromArray($pictureData);
            }

            Log::debug(self::LOG_TAG . 'OGG reader initialized', [
                'file' => $this->filePath,
                'hasVorbisComments' => !empty($this->vorbisComments),
                'hasPictures' => count($this->pictures) > 0,
            ]);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Initialization failed', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw new InvalidFlacFileException(
                "Failed to parse OGG file: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    // Standard metadata methods (implementation of MetadataReaderInterface)

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

        // Return single string if one artist, array if multiple
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
        // Try DATE first (standard Vorbis comment), fall back to YEAR
        $value = $this->getFirstValue('DATE') ?? $this->getFirstValue('YEAR');

        if ($value === null) {
            return null;
        }

        // Extract year from DATE if it contains more than just the year
        if (preg_match('/^(\d{4})/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
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
        $pictures = $this->getPicturesByType(FlacPicture::IMAGE_COVER_FRONT);

        if (empty($pictures)) {
            $pictures = $this->pictures;
        }

        return $pictures[0] ?? null;
    }

    public function getFormat(): string
    {
        return 'ogg';
    }

    public function isValid(): bool
    {
        return $this->parser !== null && $this->parser->isValid();
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    // OGG-specific methods

    /**
     * Get Vorbis comment block (raw data)
     */
    public function getVorbisCommentBlock(): ?array
    {
        return $this->parser?->getVorbisCommentBlock();
    }

    /**
     * Check if file has Vorbis comments
     */
    public function hasVorbisComments(): bool
    {
        return $this->parser?->hasVorbisComments() ?? false;
    }

    /**
     * Get all Vorbis comments
     */
    public function getVorbisComments(): array
    {
        return $this->vorbisComments;
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

    /**
     * Get track total (if available)
     */
    public function getTrackTotal(): ?int
    {
        $value = $this->getFirstValue('TRACKTOTAL');
        return $value !== null && is_numeric($value) ? (int)$value : null;
    }

    /**
     * Get disc total (if available)
     */
    public function getDiscTotal(): ?int
    {
        $value = $this->getFirstValue('DISCTOTAL');
        return $value !== null && is_numeric($value) ? (int)$value : null;
    }

    // Helper methods

    private function getFirstValue(string $field): ?string
    {
        $values = $this->getAllValues($field);
        return $values[0] ?? null;
    }

    private function getAllValues(string $field): array
    {
        return $this->vorbisComments[$field] ?? [];
    }
}
