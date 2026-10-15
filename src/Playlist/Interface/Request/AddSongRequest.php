<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'AddSongRequest',
    required: ['songId'],
    properties: [
        new OA\Property(property: 'songId', description: 'UUID of the song to add', type: 'string', format: 'uuid', example: '770f9510-f30c-52e5-b827-557755550222'),
    ],
)]
final readonly class AddSongRequest
{
    public function __construct(
        #[NotBlank(message: 'Song ID is required.')]
        public string $songId = '',
    ) {
    }
}
