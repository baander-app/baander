<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: ['ordering' => new OA\Schema(type: 'string', minLength: 1)],
    required: ['ordering'],
    component: 'SetVideoOrderingRequest',
)]
final readonly class SetVideoOrderingRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $ordering,
    ) {}
}
