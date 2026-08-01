<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\Recommendation;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RecommendationResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Recommendation UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Recommendation name'),
        new OA\Property(property: 'source_type', type: 'string', description: 'Source entity type'),
        new OA\Property(property: 'source_id', type: 'string', nullable: true, description: 'Source entity ID'),
        new OA\Property(property: 'target_type', type: 'string', description: 'Target entity type'),
        new OA\Property(property: 'target_id', type: 'string', nullable: true, description: 'Target entity ID'),
        new OA\Property(property: 'score', type: 'number', nullable: true, description: 'Relevance score'),
        new OA\Property(property: 'position', type: 'integer', nullable: true, description: 'Recommendation position'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true, description: 'User UUID'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class RecommendationResource
{
    public static function from(Recommendation $recommendation): array
    {
        return [
            'id' => $recommendation->getId()->toString(),
            'name' => $recommendation->getName(),
            'source_type' => (string) $recommendation->getSourceType(),
            'source_id' => $recommendation->getSourceId(),
            'target_type' => (string) $recommendation->getTargetType(),
            'target_id' => $recommendation->getTargetId(),
            'score' => $recommendation->getScore(),
            'position' => $recommendation->getPosition(),
            'user_id' => $recommendation->getUserId()?->toString(),
            'created_at' => $recommendation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $recommendation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
