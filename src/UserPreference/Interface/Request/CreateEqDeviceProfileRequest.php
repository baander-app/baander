<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'CreateEqDeviceProfileRequest',
    required: ['name', 'icon'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Headphones'),
        new OA\Property(property: 'icon', type: 'string', example: 'headphones'),
        new OA\Property(property: 'deviceId', type: 'string', nullable: true, example: 'audio-output-group-abc'),
    ],
)]
final readonly class CreateEqDeviceProfileRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Name is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Name must be at most 255 characters.')]
        public string $name = '',

        #[Assert\NotBlank(message: 'Icon is required.')]
        #[Assert\Choice(choices: ['headphones', 'speakers', 'hifi-speaker', 'wireless-speaker', 'car', 'tv', 'monitor', 'custom'], message: 'Invalid icon.')]
        public string $icon = 'custom',

        public ?string $deviceId = null,
    ) {
    }
}
