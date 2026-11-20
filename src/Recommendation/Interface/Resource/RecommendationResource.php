<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\Recommendation;
use OpenApi\Attributes as OA;

/**
 * @phpstan-type RecommendationData array{
 *     public_id: string, name: string, source_type: string, source_id: string,
 *     target_type: string, target_id: string, score: float, position: int|null,
 *     user_id: string|null, created_at: string, updated_at: string
 * }
 */
#[OA\Schema(
    schema: 'RecommendationResource',
    properties: [
        new OA\Property(property: 'public_id', description: 'Public recommendation identity', type: 'string'),
        new OA\Property(property: 'name', description: 'Recommendation name', type: 'string'),
        new OA\Property(property: 'source_type', description: 'Source entity type', type: 'string'),
        new OA\Property(property: 'source_id', description: 'Source entity ID', type: 'string', nullable: true),
        new OA\Property(property: 'target_type', description: 'Target entity type', type: 'string'),
        new OA\Property(property: 'target_id', description: 'Target entity ID', type: 'string', nullable: true),
        new OA\Property(property: 'score', description: 'Relevance score', type: 'number', nullable: true),
        new OA\Property(property: 'position', description: 'Recommendation position', type: 'integer', nullable: true),
        new OA\Property(property: 'user_id', description: 'Public user identity', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', description: 'Last update timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class RecommendationResource
{
    /** @return RecommendationData */
    public static function from(Recommendation $recommendation, string $sourcePublicId, string $targetPublicId, ?string $userPublicId): array
    {
        return [
            'public_id' => $recommendation->getPublicId()->toString(),
            'name' => $recommendation->getName(),
            'source_type' => (string) $recommendation->getSourceType(),
            'source_id' => $sourcePublicId,
            'target_type' => (string) $recommendation->getTargetType(),
            'target_id' => $targetPublicId,
            'score' => $recommendation->getScore(),
            'position' => $recommendation->getPosition(),
            'user_id' => $userPublicId,
            'created_at' => $recommendation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $recommendation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
