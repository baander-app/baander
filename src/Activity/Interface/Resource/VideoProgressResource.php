<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Activity\Application\DTO\VideoProgressResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: ['recorded' => new OA\Schema(type: 'boolean', description: 'Whether the report advanced the stored viewing state')],
    required: ['recorded'],
    component: 'VideoProgressResource',
)]
final class VideoProgressResource extends AbstractResource
{
    /** @param VideoProgressResult $source
     * @return array{recorded: bool}
     */
    public static function from(mixed $source): array
    {
        return ['recorded' => $source->recorded];
    }
}
