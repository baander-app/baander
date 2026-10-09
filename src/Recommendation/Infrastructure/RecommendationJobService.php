<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure;

use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\Repository\RecommendationJobRepositoryInterface;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class RecommendationJobService implements RecommendationJobPortInterface
{
    public function __construct(
        private readonly RecommendationJobRepositoryInterface $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionPortInterface $transaction,
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

    public function saveIfStatusIn(RecommendationJob $job, RecommendationJobStatus ...$from): bool
    {
        return $this->transaction->run(function () use ($job, $from): bool {
            // The row lock makes a concurrent guarded save, such as a cancel, wait for this one
            // to commit and then read the status it wrote.
            $stored = $this->entityManager->getConnection()->fetchOne(
                'SELECT status FROM recommendation_jobs WHERE id = ? FOR UPDATE',
                [$job->getId()->toString()],
            );
            if (!is_string($stored) || !in_array(RecommendationJobStatus::from($stored), $from, true)) {
                return false;
            }

            $this->repository->save($job);

            return true;
        });
    }

    public function storedStatus(Uuid $id): ?RecommendationJobStatus
    {
        return $this->repository->findStoredStatus($id);
    }
}
