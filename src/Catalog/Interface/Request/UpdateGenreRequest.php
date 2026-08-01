<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateGenreRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Updated Genre Name', nullable: true),
        new OA\Property(property: 'slug', type: 'string', nullable: true),
        new OA\Property(property: 'parentId', description: 'Public ID of the parent genre', type: 'string', nullable: true),
        new OA\Property(property: 'mbid', description: 'MusicBrainz ID', type: 'string', nullable: true),
    ],
)]
final readonly class UpdateGenreRequest
{
    public function __construct(
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $parentId = null,
        public ?string $mbid = null,
    ) {
    }
}
