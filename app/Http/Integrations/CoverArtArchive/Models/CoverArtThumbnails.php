<?php

namespace App\Http\Integrations\CoverArtArchive\Models;

use Spatie\LaravelData\Data;

class CoverArtThumbnails extends Data
{
    public function __construct(
        public string $small,
        public string $medium, // renamed from 250
        public string $large, // renamed from 500
        public string $extraLarge, // renamed from 1200
        public string $defaultLarge // renamed from large
    ) {}

    public static function fromApiData(array $data): self
    {
        return new self(
            small: $data['small'],
            medium: $data['250'],
            large: $data['500'],
            extraLarge: $data['1200'],
            defaultLarge: $data['large']
        );
    }
}