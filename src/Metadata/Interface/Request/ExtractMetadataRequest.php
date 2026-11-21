<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'path' => new OA\Schema(
            description: 'Filesystem path to the media file to analyze',
            type: 'string',
            example: '/mnt/media/music/album/song.flac',
            default: '',
        ),
    ],
    required: ['path'],
    component: 'ExtractMetadataRequest',
)]
final readonly class ExtractMetadataRequest
{
    public function __construct(
        #[NotBlank(message: 'Path is required.')]
        public string $path = '',
    ) {
    }
}
