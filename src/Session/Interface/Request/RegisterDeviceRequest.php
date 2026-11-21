<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'deviceId' => new OA\Schema(
            description: 'Browser-generated device token from localStorage; distinct from the persisted device publicId',
            type: 'string',
            format: 'uuid',
            default: '',
        ),
        'name' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'Living Room Speaker',
            default: 'Device',
        ),
    ],
    required: ['deviceId'],
    component: 'RegisterDeviceRequest',
)]
final readonly class RegisterDeviceRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'deviceId is required.')]
        #[Assert\Uuid]
        public string $deviceId = '',

        #[Assert\NotBlank(message: 'Device name is required.', normalizer: 'trim')]
        #[Assert\Length(max: 255, maxMessage: 'Device name cannot exceed {{ limit }} characters.')]
        public string $name = 'Device',
    ) {
    }
}
