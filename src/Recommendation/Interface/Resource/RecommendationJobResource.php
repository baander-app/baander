<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Application\PublicDiagnosticText;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'public_id' => new OA\Schema(type: 'string'),
        'status' => new OA\Schema(
            type: 'string',
            enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled'],
        ),
        'is_full' => new OA\Schema(type: 'boolean'),
        'total_songs' => new OA\Schema(type: 'integer'),
        'completed_songs' => new OA\Schema(type: 'integer'),
        'current_strategy' => new OA\Schema(type: 'string'),
        'strategy_counts' => new OA\Schema(
            description: 'Recommendations saved per strategy',
            type: 'object',
            additionalProperties: true,
        ),
        'progress_percentage' => new OA\Schema(type: 'number'),
        'created_at' => new OA\Schema(type: 'string', format: 'date-time'),
        'started_at' => new OA\Schema(
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'completed_at' => new OA\Schema(
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'fail_reason' => new OA\Schema(type: 'string', nullable: true),
        'metadata' => new OA\Schema(type: 'object', additionalProperties: true),
        'original_job_id' => new OA\Schema(type: 'string', nullable: true),
    ],
    required: [
        'public_id',
        'status',
        'is_full',
        'total_songs',
        'completed_songs',
        'current_strategy',
        'strategy_counts',
        'progress_percentage',
        'created_at',
        'metadata',
    ],
    component: 'RecommendationJobResource',
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
            'fail_reason' => $source->getFailReason() === null ? null : PublicDiagnosticText::redact($source->getFailReason()),
            'metadata' => PublicDiagnosticText::details($source->getMetadata()),
            'original_job_id' => $source->getOriginalJobPublicId()?->toString(),
        ];
    }
}
