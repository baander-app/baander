<?php

namespace App\Http\Integrations\CoverArtArchive\Models;

use Spatie\LaravelData\Data;

class CoverArtImage extends Data
{
    public function __construct(
        public array $types,
        public bool $front,
        public bool $back,
        public int $edit,
        public string $image,
        public string $comment,
        public bool $approved,
        public string $id,
        public CoverArtThumbnails $thumbnails
    ) {}

    public static function fromApiData(array $data): self
    {
        return new self(
            types: $data['types'],
            front: $data['front'],
            back: $data['back'],
            edit: $data['edit'],
            image: $data['image'],
            comment: $data['comment'],
            approved: $data['approved'],
            id: $data['id'],
            thumbnails: CoverArtThumbnails::fromApiData($data['thumbnails'])
        );
    }
}