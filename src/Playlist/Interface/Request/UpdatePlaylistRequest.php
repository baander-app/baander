<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated Playlist Name',
            default: null,
        ),
        'description' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated description',
            default: null,
        ),
        'isPublic' => new OA\Schema(
            type: 'boolean',
            nullable: true,
            example: true,
            default: null,
        ),
        'isCollaborative' => new OA\Schema(
            description: "The owner's switch for collaborator editing",
            type: 'boolean',
            nullable: true,
            example: false,
            default: null,
        ),
    ],
    component: 'UpdatePlaylistRequest',
)]
final readonly class UpdatePlaylistRequest
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?bool $isPublic = null,
        public ?bool $isCollaborative = null,
    ) {
    }
}
