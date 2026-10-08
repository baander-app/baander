<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Port;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface RecommendationJobPortInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function create(bool $isFull, ?Uuid $userId = null, array $metadata = [], ?Uuid $originalJobId = null): RecommendationJob;

    public function getById(Uuid $id): ?RecommendationJob;

    public function getByPublicId(PublicId $publicId): ?RecommendationJob;

    /**
     * @return RecommendationJob[]
     * @param 'pending'|'in_progress'|'completed'|'failed'|'cancelled'|null $status
     */
    public function findRecent(int $limit = 20, ?string $status = null): array;

    public function save(RecommendationJob $job): void;

    /**
     * Reads the stored status, bypassing any copy of the job this process holds, so a run
     * sees a cancellation made from another process.
     */
    public function isCancelled(Uuid $id): bool;
}
