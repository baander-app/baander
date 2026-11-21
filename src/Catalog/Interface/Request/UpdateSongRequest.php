<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'title' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated Song Title',
            default: null,
        ),
        'track' => new OA\Schema(
            description: 'Track number on the album',
            type: 'integer',
            nullable: true,
            default: null,
        ),
        'disc' => new OA\Schema(
            description: 'Disc number for multi-disc albums',
            type: 'integer',
            nullable: true,
            default: null,
        ),
        'year' => new OA\Schema(
            type: 'integer',
            nullable: true,
            default: null,
        ),
        'comment' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'lyrics' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'explicit' => new OA\Schema(
            type: 'boolean',
            nullable: true,
            default: null,
        ),
        'lockedFields' => new OA\Schema(
            description: 'Full list of locked field names. Replaces the existing list.',
            type: 'array',
            nullable: true,
            items: new OA\Schema(type: 'string'),
            default: null,
        ),
    ],
    component: 'UpdateSongRequest',
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
