<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'UpdateProfileRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Alice Johnson', maxLength: 255),
    ],
)]
final readonly class UpdateProfileRequest
{
    public function __construct(
        #[NotBlank(message: 'Name is required.')]
        #[Length(min: 1, max: 255)]
        public string $name = '',
    ) {
    }
}
