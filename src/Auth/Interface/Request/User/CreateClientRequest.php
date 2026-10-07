<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'CreateClientRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'My App', maxLength: 255),
    ],
)]
final readonly class CreateClientRequest
{
    public function __construct(
        #[NotBlank(message: 'Client name is required.')]
        #[Length(min: 1, max: 255)]
        public string $name = '',
    ) {
    }
}
