<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'ClaimSessionRequest',
    required: ['deviceId'],
    properties: [
        new OA\Property(property: 'deviceId', type: 'string', format: 'uuid', description: 'Browser-generated device token claiming the session; distinct from device publicId'),
        new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'), description: 'Optional queue to bring from local device'),
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
        #[Assert\Uuid]
        public string $deviceId = '',
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[0-9a-zA-Z_-]{21}$/')])]
        public ?array $queue = null,
        public ?int $currentTrackIndex = null,
        public ?float $position = null,
    ) {
    }
}
