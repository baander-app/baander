<?php

namespace App\Modules\Metadata\Readers\Id3;

use App\Modules\Metadata\Contracts\PictureInterface;

/**
 * ID3 picture value object
 * Represents an APIC frame (Attached Picture)
 */
class Id3Picture implements PictureInterface
{
    // Use same constants as MediaMeta for compatibility
    public const IMAGE_OTHER = 0;
    public const IMAGE_FILE_ICON = 1;
    public const IMAGE_OTHER_FILE_ICON = 2;
    public const IMAGE_COVER_FRONT = 3;
    public const IMAGE_COVER_BACK = 4;
    public const IMAGE_LEAFLET = 5;
    public const IMAGE_MEDIA = 6;
    public const IMAGE_LEAD_ARTIST = 7;
    public const IMAGE_ARTIST = 8;
    public const IMAGE_CONDUCTOR = 9;
    public const IMAGE_BAND = 10;
    public const IMAGE_COMPOSER = 11;
    public const IMAGE_LYRICIST = 12;
    public const IMAGE_RECORDING_LOCATION = 13;
    public const IMAGE_DURING_RECORDING = 14;
    public const IMAGE_DURING_PERFORMANCE = 15;
    public const IMAGE_SCREEN_CAPTURE = 16;
    public const IMAGE_FISH = 17;
    public const IMAGE_ILLUSTRATION = 18;
    public const IMAGE_BAND_LOGO = 19;
    public const IMAGE_PUBLISHER_LOGO = 20;

    public static array $typeNames = [
        'Other',
        '32x32 pixels file icon (PNG only)',
        'Other file icon',
        'Cover (front)',
        'Cover (back)',
        'Leaflet page',
        'Media (e.g. label side of CD)',
        'Lead artist/lead performer/soloist',
        'Artist/performer',
        'Conductor',
        'Band/Orchestra',
        'Composer',
        'Lyricist/text writer',
        'Recording Location',
        'During recording',
        'During performance',
        'Movie/video screen capture',
        'A bright coloured fish',
        'Illustration',
        'Band/artist logotype',
        'Publisher/Studio logotype',
    ];

    private function __construct(
        private readonly int $type,
        private readonly string $mimeType,
        private readonly string $description,
        private readonly string $imageData
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            mimeType: $data['mimeType'],
            description: $data['description'],
            imageData: $data['imageData']
        );
    }

    public function getImageType(): int
    {
        return $this->type;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getImageData(): string
    {
        return $this->imageData;
    }

    public function getImageSize(): int
    {
        return strlen($this->imageData);
    }

    public function getWidth(): int
    {
        // ID3 doesn't store dimensions, would need to parse image data
        return 0;
    }

    public function getHeight(): int
    {
        // ID3 doesn't store dimensions, would need to parse image data
        return 0;
    }

    public function getColorDepth(): int
    {
        // ID3 doesn't store color depth
        return 0;
    }

    public function getColorCount(): int
    {
        // ID3 doesn't store color count
        return 0;
    }

    public function getTypeName(): string
    {
        return self::$typeNames[$this->type] ?? "Unknown ({$this->type})";
    }

    public function getDataUri(): string
    {
        return "data:{$this->mimeType};base64," . base64_encode($this->imageData);
    }
}
