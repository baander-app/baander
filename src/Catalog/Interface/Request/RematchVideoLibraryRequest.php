<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'confirmed' => new OA\Schema(type: 'boolean', default: false),
        'force' => new OA\Schema(type: 'boolean', default: false),
    ],
    component: 'RematchVideoLibraryRequest',
)]
final readonly class RematchVideoLibraryRequest
{
    public function __construct(public bool $confirmed = false, public bool $force = false) {}
}
