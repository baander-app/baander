<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Activity\Application\DTO\WatchedStateResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'watched' => new OA\Schema(type: 'boolean'),
        'titles' => new OA\Schema(type: 'integer', minimum: 0, description: 'Owned episode or movie targets included in the action'),
    ],
    required: ['watched', 'titles'],
    component: 'WatchedStateResource',
)]
final class WatchedStateResource extends AbstractResource
{
    /** @param WatchedStateResult $source
     * @return array{watched: bool, titles: int}
     */
    public static function from(mixed $source): array
    {
        return ['watched' => $source->watched, 'titles' => $source->titles];
    }
}
