<?php

namespace App\Http\Integrations\CoverArtArchive\Models;

use Spatie\LaravelData\Data;

class CoverArtResponse extends Data
{
    public function __construct(
        public array $images
    ) {}

    public static function fromApiData(array $data): self
    {
        return new self(
            images: array_map([CoverArtImage::class, 'fromApiData'], $data['images'])
        );
    }
}