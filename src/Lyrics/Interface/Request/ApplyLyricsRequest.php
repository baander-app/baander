<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'songPublicId' => new OA\Schema(
            description: 'Public ID of the song to apply lyrics to',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            example: '0123456789abABCD_-xyz',
            default: '',
        ),
    ],
    required: ['songPublicId'],
    component: 'ApplyLyricsRequest',
)]
final readonly class ApplyLyricsRequest
{
    public function __construct(
        #[NotBlank(message: 'Song public ID is required.')]
        public string $songPublicId = '',
    ) {
    }
}
