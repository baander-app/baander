<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use App\Shared\Interface\Validator\JsonEncodedSize;
use App\UserPreference\Application\UserPreferenceLimits;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            maxLength: 255,
            example: 'Living Room',
            default: null,
        ),
        'icon' => new OA\Schema(
            type: 'string',
            nullable: true,
            enum: ['headphones', 'speakers', 'hifi-speaker', 'wireless-speaker', 'car', 'tv', 'monitor', 'custom'],
            example: 'speakers',
            default: null,
        ),
        'deviceId' => new OA\Schema(
            description: 'Client audio-output token, independent of database identity',
            type: 'string',
            nullable: true,
            maxLength: UserPreferenceLimits::EQ_DEVICE_ID_LENGTH,
            default: null,
        ),
        'payload' => new OA\Schema(
            description: 'Client EQ settings; at most 16384 bytes as compact JSON',
            type: 'object',
            nullable: true,
            default: null,
        ),
        'sortOrder' => new OA\Schema(
            type: 'integer',
            nullable: true,
            example: 0,
            default: null,
        ),
    ],
    component: 'UpdateEqDeviceProfileRequest',
)]
final readonly class UpdateEqDeviceProfileRequest
{
    /** @param array<array-key, mixed>|null $payload */
    public function __construct(
        #[Assert\Length(max: 255, maxMessage: 'Name must be at most 255 characters.')]
        public ?string $name = null,

        #[Assert\Choice(choices: ['headphones', 'speakers', 'hifi-speaker', 'wireless-speaker', 'car', 'tv', 'monitor', 'custom'], message: 'Invalid icon.')]
        public ?string $icon = null,

        #[Assert\Length(max: UserPreferenceLimits::EQ_DEVICE_ID_LENGTH)]
        public ?string $deviceId = null,

        #[Assert\Type(type: 'array', message: 'Payload must be an object.')]
        #[JsonEncodedSize(max: UserPreferenceLimits::EQ_PAYLOAD_BYTES)]
        public ?array $payload = null,

        #[Assert\Type(type: 'integer', message: 'Sort order must be an integer.')]
        public ?int $sortOrder = null,
    ) {
    }
}
