<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'UpdateEqDeviceProfileRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Living Room'),
        new OA\Property(property: 'icon', type: 'string', example: 'speakers'),
        new OA\Property(property: 'deviceId', type: 'string', nullable: true),
        new OA\Property(property: 'payload', type: 'object'),
        new OA\Property(property: 'sortOrder', type: 'integer', example: 0),
    ],
)]
final readonly class UpdateEqDeviceProfileRequest
{
    public function __construct(
        #[Assert\Length(max: 255, maxMessage: 'Name must be at most 255 characters.')]
        public ?string $name = null,

        #[Assert\Choice(choices: ['headphones', 'speakers', 'hifi-speaker', 'wireless-speaker', 'car', 'tv', 'monitor', 'custom'], message: 'Invalid icon.')]
        public ?string $icon = null,

        public ?string $deviceId = null,

        #[Assert\Type(type: 'array', message: 'Payload must be an object.')]
        public ?array $payload = null,

        #[Assert\Type(type: 'integer', message: 'Sort order must be an integer.')]
        public ?int $sortOrder = null,
    ) {
    }
}
