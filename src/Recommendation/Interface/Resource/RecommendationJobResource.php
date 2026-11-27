<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Application\PublicDiagnosticText;
use App\Shared\Interface\Resource\AbstractResource;
use Closure;
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
    /** Metadata keys naming who started or requeued a job: a user's internal ID, `cli` or `system`. */
    private const array ACTOR_KEYS = ['triggered_by', 'requeued_by'];

    /** @param array<string, string> $actorPublicIds stored actor => that user's public ID, as actorPublicIds() returns it */
    public static function from(mixed $source, array $actorPublicIds = []): array
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
            'metadata' => PublicDiagnosticText::details(self::withPublicActors($source->getMetadata(), $actorPublicIds)),
            'original_job_id' => $source->getOriginalJobPublicId()?->toString(),
        ];
    }

    /**
     * @param array<mixed> $jobs
     * @param array<string, string> $actorPublicIds stored actor => that user's public ID, as actorPublicIds() returns it
     *
     * @return list<array<string, mixed>>
     */
    public static function collectionWithActors(array $jobs, array $actorPublicIds): array
    {
        $resources = [];
        foreach ($jobs as $job) {
            $resources[] = self::from($job, $actorPublicIds);
        }

        return $resources;
    }

    /**
     * Looks up each distinct actor across the jobs once. An actor the lookup does not resolve is left
     * out and shows as stored: `cli` and `system` unchanged, a user ID redacted.
     *
     * @param array<mixed> $jobs
     * @param Closure(string): ?string $publicUserId
     *
     * @return array<string, string>
     */
    public static function actorPublicIds(array $jobs, Closure $publicUserId): array
    {
        $actors = [];
        foreach ($jobs as $job) {
            assert($job instanceof RecommendationJob);
            foreach (self::ACTOR_KEYS as $key) {
                $actor = $job->getMetadata()[$key] ?? null;
                if (is_string($actor)) {
                    $actors[] = $actor;
                }
            }
        }

        $publicIds = [];
        foreach (array_unique($actors) as $actor) {
            $publicId = $publicUserId($actor);
            if ($publicId !== null) {
                $publicIds[$actor] = $publicId;
            }
        }

        return $publicIds;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, string> $actorPublicIds
     *
     * @return array<string, mixed>
     */
    private static function withPublicActors(array $metadata, array $actorPublicIds): array
    {
        foreach (self::ACTOR_KEYS as $key) {
            $actor = $metadata[$key] ?? null;
            if (is_string($actor) && isset($actorPublicIds[$actor])) {
                $metadata[$key] = $actorPublicIds[$actor];
            }
        }

        return $metadata;
    }
}
