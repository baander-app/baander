<?php

declare(strict_types=1);

namespace App\Library\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'CreateLibraryRequest',
    required: ['name', 'path', 'type'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'My Music', maxLength: 255),
        new OA\Property(property: 'path', description: 'Filesystem path to the library root', type: 'string', example: '/mnt/media/music'),
        new OA\Property(property: 'type', description: 'Library type (e.g. music, movies, videos)', type: 'string'),
        new OA\Property(property: 'filesystemType', description: 'Filesystem backend (e.g. local)', type: 'string'),
        new OA\Property(property: 'sortOrder', type: 'integer', example: 0),
        new OA\Property(property: 'slug', description: 'URL-friendly slug', type: 'string', nullable: true),
    ],
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
