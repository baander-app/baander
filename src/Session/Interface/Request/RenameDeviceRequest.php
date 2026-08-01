<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'RenameDeviceRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Kitchen Speaker', maxLength: 255),
    ],
)]
final readonly class RenameDeviceRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Device name is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Device name cannot exceed {{ limit }} characters.')]
        public string $name = '',
    ) {
    }
}
