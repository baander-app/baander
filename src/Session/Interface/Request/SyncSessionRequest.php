<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'SyncSessionRequest',
    required: ['queue', 'currentTrackIndex', 'position', 'playbackState'],
    properties: [
        new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string'), description: 'Ordered list of track IDs'),
        new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0, example: 0),
        new OA\Property(property: 'position', type: 'number', minimum: 0.0, example: 45.2),
        new OA\Property(property: 'playbackState', type: 'string', enum: ['playing', 'paused', 'stopped'], example: 'playing'),
    ],
)]
final readonly class SyncSessionRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Queue is required.')]
        public array $queue = [],

        #[Assert\NotNull(message: 'Current track index is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Current track index cannot be negative.')]
        public int $currentTrackIndex = 0,

        #[Assert\NotNull(message: 'Position is required.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Position cannot be negative.')]
        public float $position = 0.0,

        #[Assert\NotBlank(message: 'Playback state is required.')]
        #[Assert\Choice(choices: ['playing', 'paused', 'stopped'], message: 'Invalid playback state.')]
        public string $playbackState = 'stopped',
    ) {
    }
}
