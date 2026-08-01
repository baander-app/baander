<?php

declare(strict_types=1);

namespace App\Metadata\Domain\Model;

final readonly class CoverArt
{
    // Picture type constants (ID3 APIC / FLAC METADATA_BLOCK_PICTURE)
    public const int TYPE_OTHER = 0;
    public const int TYPE_FILE_ICON = 1;
    public const int TYPE_OTHER_FILE_ICON = 2;
    public const int TYPE_COVER_FRONT = 3;
    public const int TYPE_COVER_BACK = 4;
    public const int TYPE_LEAFLET = 5;
    public const int TYPE_MEDIA = 6;
    public const int TYPE_LEAD_ARTIST = 7;
    public const int TYPE_ARTIST = 8;
    public const int TYPE_CONDUCTOR = 9;
    public const int TYPE_BAND = 10;
    public const int TYPE_COMPOSER = 11;
    public const int TYPE_LYRICIST = 12;
    public const int TYPE_RECORDING_LOCATION = 13;
    public const int TYPE_DURING_RECORDING = 14;
    public const int TYPE_DURING_PERFORMANCE = 15;
    public const int TYPE_SCREEN_CAPTURE = 16;
    public const int TYPE_FISH = 17;
    public const int TYPE_ILLUSTRATION = 18;
    public const int TYPE_BAND_LOGO = 19;
    public const int TYPE_PUBLISHER_LOGO = 20;

    private const array TYPE_NAMES = [
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

    public function __construct(
        private readonly int $type,
        private readonly string $mimeType,
        private readonly string $description,
        private readonly string $imageData,
        private readonly int $width = 0,
        private readonly int $height = 0,
        private readonly int $colorDepth = 0,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            mimeType: $data['mimeType'],
            description: $data['description'],
            imageData: $data['imageData'],
            width: $data['width'] ?? 0,
            height: $data['height'] ?? 0,
            colorDepth: $data['colorDepth'] ?? 0,
        );
    }

    public function getType(): int
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
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getColorDepth(): int
    {
        return $this->colorDepth;
    }

    public function getTypeName(): string
    {
        return self::TYPE_NAMES[$this->type] ?? "Unknown ({$this->type})";
    }

    public function getDataUri(): string
    {
        return "data:{$this->mimeType};base64," . base64_encode($this->imageData);
    }

    public function isCoverFront(): bool
    {
        return $this->type === self::TYPE_COVER_FRONT;
    }
}
