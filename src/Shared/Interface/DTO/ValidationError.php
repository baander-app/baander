<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'Validation error response',
    type: 'object',
    properties: [
        'error' => new OA\Schema(
            type: 'object',
            properties: [
                'message' => new OA\Schema(type: 'string', example: 'Validation failed'),
                'code' => new OA\Schema(type: 'integer', example: 422),
                'details' => new OA\Schema(description: 'Field-level validation errors', type: 'object'),
            ],
        ),
    ],
    required: ['error'],
    component: 'ValidationError',
)]
final readonly class ValidationError
{
}
