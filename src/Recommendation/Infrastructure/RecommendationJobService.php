<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure;

use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\Repository\RecommendationJobRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class RecommendationJobService implements RecommendationJobPortInterface
{
    public function __construct(
        private readonly RecommendationJobRepositoryInterface $repository,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function create(bool $isFull, ?Uuid $userId = null, array $metadata = [], ?Uuid $originalJobId = null): RecommendationJob
    {
        $job = RecommendationJob::create($isFull, $userId, $metadata, $originalJobId);
        $this->repository->save($job);

        return $job;
    }

    public function getById(Uuid $id): ?RecommendationJob
    {
        return $this->repository->findByUuid($id);
    }

    public function getByPublicId(PublicId $publicId): ?RecommendationJob
    {
        return $this->repository->findByPublicId($publicId);
    }

    public function findRecent(int $limit = 20, ?string $status = null): array
    {
        return $this->repository->findRecent($limit, $status);
    }

    public function save(RecommendationJob $job): void
    {
        $this->repository->save($job);
    }
}
