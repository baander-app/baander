<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Count;

#[OA\Schema(
    schema: 'MatchMetadataRequest',
    required: ['path', 'candidates'],
    properties: [
        new OA\Property(property: 'path', description: 'Filesystem path to the media file', type: 'string', example: '/mnt/media/music/album/song.flac'),
        new OA\Property(property: 'candidates', description: 'Array of metadata match candidates', type: 'array', items: new OA\Items(type: 'object')),
    ],
)]
final readonly class MatchMetadataRequest
{
    public function __construct(
        #[NotBlank(message: 'Path is required.')]
        public string $path = '',
        #[NotBlank(message: 'Candidates are required.')]
        #[Count(min: 1, minMessage: 'Non-empty "candidates" array is required.')]
        public array $candidates = [],
    ) {
    }
}
