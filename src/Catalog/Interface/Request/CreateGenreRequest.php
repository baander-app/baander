<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'CreateGenreRequest',
    required: ['name', 'slug'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Jazz'),
        new OA\Property(property: 'slug', type: 'string', example: 'jazz'),
        new OA\Property(property: 'parentId', description: 'UUID of the parent genre', type: 'string', nullable: true),
        new OA\Property(property: 'mbid', description: 'MusicBrainz ID', type: 'string', nullable: true),
    ],
)]
final readonly class CreateGenreRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Genre name is required.')]
        public string $name,
        #[Assert\NotBlank(message: 'Genre slug is required.')]
        public string $slug,
        public ?string $parentId = null,
        public ?string $mbid = null,
    ) {
    }
}
