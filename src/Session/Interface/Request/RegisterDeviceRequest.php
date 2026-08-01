<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'RegisterDeviceRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Living Room Speaker', maxLength: 255),
    ],
)]
final readonly class RegisterDeviceRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Device name is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Device name cannot exceed {{ limit }} characters.')]
        public string $name = '',
    ) {
    }
}
