<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    type: 'object',
    properties: [
        'expectedRevision' => new OA\Schema(
            description: 'Revision returned with the displayed playlist order',
            type: 'string',
            minLength: 64,
            maxLength: 64,
            pattern: '^[a-f0-9]{64}$',
            default: '',
        ),
        'songIds' => new OA\Schema(
            description: 'Ordered array of song public IDs',
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                minLength: 21,
                maxLength: 21,
                pattern: '^[0-9a-zA-Z_-]{21}$',
            ),
            minItems: 1,
            example: ['aB3dE5fG7hJ9kL1mN3pQr', 'bB3dE5fG7hJ9kL1mN3pQr'],
            default: [],
        ),
    ],
    required: ['expectedRevision', 'songIds'],
    component: 'ReorderSongsRequest',
)]
final readonly class ReorderSongsRequest
{
    /**
     * @param array<array-key, mixed> $songIds
     */
    public function __construct(
        #[NotBlank(message: 'Song IDs are required.')]
        #[Count(min: 1, minMessage: 'The "song_ids" field must not be empty.')]
        public array $songIds = [],
        #[NotBlank(message: 'The playlist revision is required.')]
        #[Regex(pattern: '/^[a-f0-9]{64}$/D', message: 'The playlist revision must be 64 lowercase hexadecimal characters.')]
        public string $expectedRevision = '',
    ) {
    }
}
