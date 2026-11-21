<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'version' => new OA\Schema(
            type: 'integer',
            enum: [1],
        ),
        'families' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(ref: LiveFamily::class),
        ),
        'authorizationFingerprint' => new OA\Schema(type: 'string', pattern: '^[0-9a-f]{64}$'),
        'health' => new OA\Schema(
            type: 'object',
            properties: [
                'state' => new OA\Schema(
                    type: 'string',
                    enum: ['current', 'degraded'],
                ),
                'oldestPendingAgeMs' => new OA\Schema(
                    type: 'integer',
                    nullable: true,
                    minimum: 0,
                ),
            ],
            required: ['state', 'oldestPendingAgeMs'],
        ),
    ],
    required: ['version', 'families', 'authorizationFingerprint', 'health'],
    component: 'LiveRecoveryResource',
)]
final class LiveRecoveryResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof LiveSnapshot);
        $age = $source->health->oldestPendingAgeMs;

        return [
            'version' => 1,
            'families' => array_map(static fn(LiveFamily $family): string => $family->value, $source->families),
            'authorizationFingerprint' => $source->authorizationFingerprint,
            'health' => ['state' => $source->health->isDegraded() ? 'degraded' : 'current', 'oldestPendingAgeMs' => $age],
        ];
    }
}
