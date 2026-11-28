<?php

declare(strict_types=1);

namespace App\Activity\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: ['watched' => new OA\Schema(type: 'boolean')],
    required: ['watched'],
    component: 'SetWatchedRequest',
)]
final readonly class SetWatchedRequest
{
    public function __construct(public bool $watched) {}
}
