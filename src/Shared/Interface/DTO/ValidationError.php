<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ValidationError',
    description: 'Validation error response',
    properties: [
        new OA\Property(property: 'error', properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Validation failed'),
            new OA\Property(property: 'code', type: 'integer', example: 422),
            new OA\Property(property: 'details', type: 'object', description: 'Field-level validation errors'),
        ], type: 'object'),
    ],
)]
final readonly class ValidationError
{
}
