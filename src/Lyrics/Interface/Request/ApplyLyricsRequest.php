<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'ApplyLyricsRequest',
    required: ['songPublicId'],
    properties: [
        new OA\Property(property: 'songPublicId', description: 'Public ID of the song to apply lyrics to', type: 'string', example: 'abc123def456'),
    ],
)]
final readonly class ApplyLyricsRequest
{
    public function __construct(
        #[NotBlank(message: 'Song public ID is required.')]
        public string $songPublicId = '',
    ) {
    }
}
