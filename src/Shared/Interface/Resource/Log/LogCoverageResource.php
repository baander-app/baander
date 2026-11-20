<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['complete', 'reasons'],
    properties: [
        new OA\Property(property: 'complete', type: 'boolean'),
        new OA\Property(property: 'reasons', type: 'array', items: new OA\Items(type: 'string')),
    ],
)]
final class LogCoverageResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['complete', 'reasons']));
    }
}
