<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Repository;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface RecommendationJobRepositoryInterface
{
    public function save(RecommendationJob $job): void;

    public function persist(RecommendationJob $job): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?RecommendationJob;

    public function findByPublicId(PublicId $publicId): ?RecommendationJob;

    /** @return RecommendationJob[] */
    public function findPendingJobs(): array;

    /** @return RecommendationJob[] */
    public function findInProgressJobs(): array;

    /**
     * @return RecommendationJob[]
     * @param 'pending'|'in_progress'|'completed'|'failed'|'cancelled'|null $status
     */
    public function findRecent(int $limit = 20, ?string $status = null): array;

    public function delete(RecommendationJob $job): void;
}
