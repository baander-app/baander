<?php

declare(strict_types=1);

namespace App\Library\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'My Music',
            default: '',
        ),
        'path' => new OA\Schema(
            description: 'Filesystem path to the library root',
            type: 'string',
            example: '/mnt/media/music',
            default: '',
        ),
        'type' => new OA\Schema(
            description: 'Library type (e.g. music, movies, videos)',
            type: 'string',
            default: '',
        ),
        'filesystemType' => new OA\Schema(
            description: 'Filesystem backend (e.g. local)',
            type: 'string',
            default: 'local',
        ),
        'sortOrder' => new OA\Schema(
            type: 'integer',
            example: 0,
            default: 0,
        ),
        'slug' => new OA\Schema(
            description: 'URL-friendly slug',
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    required: ['name', 'path', 'type'],
    component: 'CreateLibraryRequest',
)]
final readonly class CreateLibraryRequest
{
    public function __construct(
        #[NotBlank(message: 'Name is required.')]
        #[Length(max: 255)]
        public string $name = '',
        #[NotBlank(message: 'Path is required.')]
        public string $path = '',
        #[NotBlank(message: 'Type is required.')]
        public string $type = '',
        #[NotBlank(message: 'Filesystem type is required.')]
        public string $filesystemType = 'local',
        public int $sortOrder = 0,
        public ?string $slug = null,
    ) {
    }
}
