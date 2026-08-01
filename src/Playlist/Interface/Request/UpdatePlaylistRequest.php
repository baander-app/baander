<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePlaylistRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Updated Playlist Name', nullable: true),
        new OA\Property(property: 'description', type: 'string', example: 'Updated description', nullable: true),
        new OA\Property(property: 'isPublic', type: 'boolean', example: true, nullable: true),
    ],
)]
final readonly class UpdatePlaylistRequest
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?bool $isPublic = null,
    ) {
    }
}
