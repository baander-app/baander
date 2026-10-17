<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'AddSongRequest',
    required: ['songId'],
    properties: [
        new OA\Property(property: 'songId', description: 'Public ID of the song to add', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21, example: 'aB3dE5fG7hJ9kL1mN3pQr'),
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
