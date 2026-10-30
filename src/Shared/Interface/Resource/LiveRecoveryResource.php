<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LiveRecoveryResource',
    required: ['version', 'families', 'authorizationFingerprint', 'health'],
    properties: [
        new OA\Property(property: 'version', type: 'integer', enum: [1]),
        new OA\Property(property: 'families', type: 'array', items: new OA\Items(ref: new Model(type: LiveFamily::class))),
        new OA\Property(property: 'authorizationFingerprint', type: 'string', pattern: '^[0-9a-f]{64}$'),
        new OA\Property(property: 'health', type: 'object', required: ['state', 'oldestPendingAgeMs'], properties: [
            new OA\Property(property: 'state', type: 'string', enum: ['current', 'degraded']),
            new OA\Property(property: 'oldestPendingAgeMs', type: 'integer', minimum: 0, nullable: true),
        ]),
    ],
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
