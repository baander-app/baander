<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RecommendationJobResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'public_id', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled']),
        new OA\Property(property: 'is_full', type: 'boolean'),
        new OA\Property(property: 'total_songs', type: 'integer'),
        new OA\Property(property: 'completed_songs', type: 'integer'),
        new OA\Property(property: 'current_strategy', type: 'string'),
        new OA\Property(property: 'strategy_counts', type: 'object', description: 'Recommendations saved per strategy', additionalProperties: true),
        new OA\Property(property: 'progress_percentage', type: 'number'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'fail_reason', type: 'string', nullable: true),
        new OA\Property(property: 'metadata', type: 'object', additionalProperties: true),
        new OA\Property(property: 'original_job_id', type: 'string', format: 'uuid', nullable: true),
    ],
)]
final class RecommendationJobResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof RecommendationJob);

        $progress = 0.0;
        if ($source->getTotalSongs() > 0) {
            $progress = ($source->getCompletedSongs() / $source->getTotalSongs()) * 100;
        }

        return [
            'id' => $source->getId()->toString(),
            'public_id' => $source->getPublicId()->toString(),
            'status' => $source->getStatus()->value,
            'is_full' => $source->isFull(),
            'total_songs' => $source->getTotalSongs(),
            'completed_songs' => $source->getCompletedSongs(),
            'current_strategy' => $source->getCurrentStrategy(),
            'strategy_counts' => $source->getStrategyCounts(),
            'progress_percentage' => round($progress, 2),
            'created_at' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'started_at' => $source->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $source->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'fail_reason' => $source->getFailReason(),
            'metadata' => $source->getMetadata(),
            'original_job_id' => $source->getOriginalJobId()?->toString(),
        ];
    }
}
