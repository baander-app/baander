<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'songId' => new OA\Schema(
            description: 'Public ID of the song to add',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            example: 'aB3dE5fG7hJ9kL1mN3pQr',
            default: '',
        ),
    ],
    required: ['songId'],
    component: 'AddSongRequest',
)]
final readonly class AddSongRequest
{
    public function __construct(
        #[NotBlank(message: 'Song ID is required.')]
        public string $songId = '',
    ) {
    }
}
