<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: ['enabled' => new OA\Schema(type: 'boolean')],
    required: ['enabled'],
    component: 'SetMetadataProviderEnabledRequest',
)]
final readonly class SetMetadataProviderEnabledRequest
{
    public function __construct(public bool $enabled) {}
}
