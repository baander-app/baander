<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            minLength: 1,
            maxLength: 255,
            example: 'My App',
            default: '',
        ),
    ],
    required: ['name'],
    component: 'CreateClientRequest',
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
