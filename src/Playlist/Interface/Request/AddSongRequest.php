<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'AddSongRequest',
    required: ['songId'],
    properties: [
        new OA\Property(property: 'songId', description: 'Public ID of the song to add', type: 'string', example: 'aB3dE5fG7hJ9kL1mN3p'),
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
