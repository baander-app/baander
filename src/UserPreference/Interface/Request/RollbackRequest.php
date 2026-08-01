<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'RollbackRequest',
    required: ['version'],
    properties: [
        new OA\Property(property: 'version', type: 'integer', minimum: 1, example: 2),
    ],
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
