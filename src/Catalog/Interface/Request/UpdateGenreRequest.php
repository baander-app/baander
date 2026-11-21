<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated Genre Name',
            default: null,
        ),
        'slug' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'parentId' => new OA\Schema(
            description: 'Public ID of the parent genre',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: null,
        ),
        'mbid' => new OA\Schema(
            description: 'MusicBrainz ID',
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    component: 'UpdateGenreRequest',
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
