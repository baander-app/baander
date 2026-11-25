<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use App\UserPreference\Application\UserPreferenceLimits;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'Headphones',
            default: '',
        ),
        'icon' => new OA\Schema(
            type: 'string',
            enum: ['headphones', 'speakers', 'hifi-speaker', 'wireless-speaker', 'car', 'tv', 'monitor', 'custom'],
            example: 'headphones',
            default: 'custom',
        ),
        'deviceId' => new OA\Schema(
            description: 'Client audio-output token, independent of database identity',
            type: 'string',
            nullable: true,
            maxLength: UserPreferenceLimits::EQ_DEVICE_ID_LENGTH,
            example: 'audio-output-group-abc',
            default: null,
        ),
    ],
    required: ['name', 'icon'],
    component: 'CreateEqDeviceProfileRequest',
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

        #[Assert\Length(max: UserPreferenceLimits::EQ_DEVICE_ID_LENGTH)]
        public ?string $deviceId = null,
    ) {
    }
}
