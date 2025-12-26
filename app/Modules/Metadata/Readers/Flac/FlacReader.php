<?php

namespace App\Modules\Metadata\Readers\Flac;

use App\Modules\Metadata\Contracts\Flac\FlacReaderInterface;
use App\Modules\Metadata\Contracts\PictureInterface;
use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use Illuminate\Support\Facades\Log;

/**
 * FLAC metadata reader
 * Implements Vorbis Comments, METADATA_BLOCK_PICTURE, and SEEKTABLE support
 */
class FlacReader implements FlacReaderInterface
{
    private const string LOG_TAG = 'FlacReader ';

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
    private ?FlacParser $parser = null;
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
            $this->parser = new FlacParser($this->filePath);
            $this->parser->parse();

            // Cache Vorbis comments for easy access
            $commentBlock = $this->parser->getVorbisCommentBlock();
            if ($commentBlock) {
                $this->vorbisComments = $commentBlock['comments'];
            }

            // Convert picture blocks to FlacPicture objects
            foreach ($this->parser->getPictureBlocks() as $pictureData) {
                $this->pictures[] = FlacPicture::fromArray($pictureData);
            }

            Log::debug(self::LOG_TAG . 'FLAC reader initialized', [
                'file' => $this->filePath,
                'hasVorbisComments' => !empty($this->vorbisComments),
                'hasPictures' => count($this->pictures) > 0,
                'hasSeektable' => $this->parser->hasSeektable(),
            ]);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Initialization failed', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw new InvalidFlacFileException(
                "Failed to parse FLAC file: {$e->getMessage()}",
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
        // Try DATE first (standard Vorbis), fall back to YEAR
        $value = $this->getFirstValue('DATE');
        if ($value === null) {
            $value = $this->getFirstValue('YEAR');
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
        // COMMENT field can contain multiple entries
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

    // FLAC-specific methods (FlacReaderInterface)

    public function getMetadataBlocks(): array
    {
        return $this->parser->getMetadataBlocks();
    }

    public function getSeektable(): ?array
    {
        return $this->parser->getSeektableBlock();
    }

    public function getStreamInfo(): ?array
    {
        return $this->parser->getStreamInfo();
    }

    public function getVorbisComments(): array
    {
        return $this->vorbisComments;
    }

    public function getVorbisCommentField(string $field): array
    {
        return $this->getAllValues($field);
    }

    public function getPictures(): array
    {
        return $this->pictures;
    }

    public function getFirstPicture(): ?object
    {
        return $this->pictures[0] ?? null;
    }

    public function getPicturesByType(int $type): array
    {
        return array_filter($this->pictures, fn($p) => $p->getImageType() === $type);
    }

    public function getFormat(): string
    {
        return 'flac';
    }

    public function isValid(): bool
    {
        return $this->parser !== null && $this->parser->isValid();
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    // Helper methods

    private function getFirstValue(string $field): ?string
    {
        $values = $this->getAllValues($field);
        return $values[0] ?? null;
    }

    private function getAllValues(string $field): array
    {
        $normalizedField = strtoupper($field);
        return $this->vorbisComments[$normalizedField] ?? [];
    }
}
