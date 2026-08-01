<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'CreatePlaylistRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'My Playlist', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', example: 'A great playlist', nullable: true),
        new OA\Property(property: 'isPublic', type: 'boolean', example: false),
        new OA\Property(property: 'isCollaborative', type: 'boolean', example: false),
        new OA\Property(property: 'isSmart', type: 'boolean', example: false),
        new OA\Property(property: 'smartRules', description: 'Rules for smart playlist auto-population', type: 'array', items: new OA\Items(type: 'object')),
    ],
)]
final readonly class CreatePlaylistRequest
{
    public function __construct(
        #[NotBlank(message: 'Name is required.')]
        #[Length(max: 255)]
        public string $name = '',
        public ?string $description = null,
        public bool $isPublic = false,
        public bool $isCollaborative = false,
        public bool $isSmart = false,
        public array $smartRules = [],
    ) {
    }
}
