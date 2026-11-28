<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: ['providers' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string'))],
    required: ['providers'],
    component: 'LibraryProviderOrderRequest',
)]
final readonly class LibraryProviderOrderRequest
{
    /** @param list<string> $providers */
    public function __construct(
        #[Assert\All([new Assert\Type('string')])]
        public array $providers,
    ) {}
}
