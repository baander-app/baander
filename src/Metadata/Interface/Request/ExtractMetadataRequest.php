<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'ExtractMetadataRequest',
    required: ['path'],
    properties: [
        new OA\Property(property: 'path', description: 'Filesystem path to the media file to analyze', type: 'string', example: '/mnt/media/music/album/song.flac'),
    ],
)]
final readonly class ExtractMetadataRequest
{
    public function __construct(
        #[NotBlank(message: 'Path is required.')]
        public string $path = '',
    ) {
    }
}
