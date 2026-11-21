<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'version' => new OA\Schema(
            type: 'integer',
            minimum: 1,
            example: 2,
            default: 0,
        ),
    ],
    required: ['version'],
    component: 'RollbackRequest',
)]
final readonly class RollbackRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Version is required.')]
        #[Assert\GreaterThanOrEqual(value: 1, message: 'Version must be at least 1.')]
        public int $version = 0,
    ) {
    }
}
