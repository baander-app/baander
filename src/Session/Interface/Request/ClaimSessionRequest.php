<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'ClaimSessionRequest',
    required: ['deviceId'],
    properties: [
        new OA\Property(property: 'deviceId', type: 'string', format: 'uuid', description: 'The device claiming the session'),
        new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string'), description: 'Optional queue to bring from local device'),
        new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0, description: 'Optional current track index'),
        new OA\Property(property: 'position', type: 'number', minimum: 0, description: 'Optional playback position'),
    ],
)]
final readonly class ClaimSessionRequest
{
    /**
     * @param array<string>|null $queue
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Device ID is required.')]
        public string $deviceId = '',
        public ?array $queue = null,
        public ?int $currentTrackIndex = null,
        public ?float $position = null,
    ) {
    }
}
