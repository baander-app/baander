<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Resource;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(type: 'string'),
        'name' => new OA\Schema(type: 'string'),
        'enabled' => new OA\Schema(type: 'boolean'),
        'configured' => new OA\Schema(type: 'boolean'),
        'attributionText' => new OA\Schema(type: 'string'),
        'attributionUrl' => new OA\Schema(type: 'string'),
    ],
    required: ['id', 'name', 'enabled', 'configured', 'attributionText', 'attributionUrl'],
    component: 'MetadataProviderResource',
)]
final class MetadataProviderResource {}
