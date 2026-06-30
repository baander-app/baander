<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateSongRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string', example: 'Updated Song Title', nullable: true),
        new OA\Property(property: 'track', description: 'Track number on the album', type: 'integer', nullable: true),
        new OA\Property(property: 'disc', description: 'Disc number for multi-disc albums', type: 'integer', nullable: true),
        new OA\Property(property: 'year', type: 'integer', nullable: true),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'lyrics', type: 'string', nullable: true),
        new OA\Property(property: 'explicit', type: 'boolean', nullable: true),
        new OA\Property(property: 'lockedFields', description: 'Full list of locked field names. Replaces the existing list.', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    ],
)]
final readonly class UpdateSongRequest
{
    /**
     * @param string[]|null $lockedFields
     */
    public function __construct(
        public ?string $title = null,
        public ?int $track = null,
        public ?int $disc = null,
        public ?int $year = null,
        public ?string $comment = null,
        public ?string $lyrics = null,
        public ?bool $explicit = null,
        public ?array $lockedFields = null,
    ) {
    }
}
