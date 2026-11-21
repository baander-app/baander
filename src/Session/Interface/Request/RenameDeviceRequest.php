<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            maxLength: 255,
            example: 'Kitchen Speaker',
            default: '',
        ),
    ],
    required: ['name'],
    component: 'RenameDeviceRequest',
)]
final readonly class RenameDeviceRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Device name is required.', normalizer: 'trim')]
        #[Assert\Length(max: 255, maxMessage: 'Device name cannot exceed {{ limit }} characters.')]
        public string $name = '',
    ) {
    }
}
