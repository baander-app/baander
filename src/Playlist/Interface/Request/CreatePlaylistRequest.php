<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'My Playlist',
            default: '',
        ),
        'description' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'A great playlist',
            default: null,
        ),
        'isPublic' => new OA\Schema(
            type: 'boolean',
            example: false,
            default: false,
        ),
        'isCollaborative' => new OA\Schema(
            type: 'boolean',
            example: false,
            default: false,
        ),
        'isSmart' => new OA\Schema(
            type: 'boolean',
            example: false,
            default: false,
        ),
        'smartRules' => new OA\Schema(
            description: 'Rules for smart playlist auto-population',
            type: 'array',
            items: new OA\Schema(type: 'object'),
            default: [],
        ),
    ],
    required: ['name'],
    component: 'CreatePlaylistRequest',
)]
final readonly class CreatePlaylistRequest
{
    /**
     * @param array<array-key, mixed> $smartRules
     */
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
