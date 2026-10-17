<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'CreateSessionRequest',
    required: ['queue', 'currentTrackIndex', 'position'],
    properties: [
        new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'), description: 'Ordered list of track IDs'),
        new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0, example: 0),
        new OA\Property(property: 'position', type: 'number', minimum: 0.0, example: 0.0),
    ],
)]
final readonly class CreateSessionRequest
{
    /**
     * @param array<array-key, mixed> $queue
     */
    public function __construct(
        #[Assert\NotNull(message: 'Queue is required.')]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[0-9a-zA-Z_-]{21}$/')])]
        public array $queue = [],

        #[Assert\NotNull(message: 'Current track index is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Current track index cannot be negative.')]
        public int $currentTrackIndex = 0,

        #[Assert\NotNull(message: 'Position is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Position cannot be negative.')]
        public float $position = 0.0,
    ) {
    }
}
