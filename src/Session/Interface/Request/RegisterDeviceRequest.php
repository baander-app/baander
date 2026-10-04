<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'RegisterDeviceRequest',
    required: ['deviceId'],
    properties: [
        new OA\Property(property: 'deviceId', type: 'string', format: 'uuid', description: 'Persistent device identifier from localStorage'),
        new OA\Property(property: 'name', type: 'string', example: 'Living Room Speaker', maxLength: 255),
    ],
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
