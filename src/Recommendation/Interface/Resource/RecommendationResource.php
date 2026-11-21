<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\Recommendation;
use OpenApi\Spec as OA;

/**
 * @phpstan-type RecommendationData array{
 *     public_id: string, name: string, source_type: string, source_id: string,
 *     target_type: string, target_id: string, score: float, position: int|null,
 *     user_id: string|null, created_at: string, updated_at: string
 * }
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'public_id' => new OA\Schema(description: 'Public recommendation identity', type: 'string'),
        'name' => new OA\Schema(description: 'Recommendation name', type: 'string'),
        'source_type' => new OA\Schema(description: 'Source entity type', type: 'string'),
        'source_id' => new OA\Schema(
            description: 'Source entity ID',
            type: 'string',
            nullable: true,
        ),
        'target_type' => new OA\Schema(description: 'Target entity type', type: 'string'),
        'target_id' => new OA\Schema(
            description: 'Target entity ID',
            type: 'string',
            nullable: true,
        ),
        'score' => new OA\Schema(
            description: 'Relevance score',
            type: 'number',
            nullable: true,
        ),
        'position' => new OA\Schema(
            description: 'Recommendation position',
            type: 'integer',
            nullable: true,
        ),
        'user_id' => new OA\Schema(
            description: 'Public user identity',
            type: 'string',
            nullable: true,
        ),
        'created_at' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updated_at' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['public_id', 'name', 'source_type', 'target_type', 'created_at', 'updated_at'],
    component: 'RecommendationResource',
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
