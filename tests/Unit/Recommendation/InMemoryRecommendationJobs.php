<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation;

use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * Keeps jobs by ID. `storedStatus` stands in for the database row another process can
 * change, such as an admin cancelling the job while it runs.
 */
final class InMemoryRecommendationJobs implements RecommendationJobPortInterface
{
    /** @var array<string, RecommendationJob> */
    public array $jobs = [];

    /** @var array<string, RecommendationJobStatus> */
    public array $storedStatus = [];

    /** @var list<string> the status of each save, in order */
    public array $savedStatuses = [];

    /** @var (callable(RecommendationJob): void)|null runs on every save, after it is recorded */
    public $onSave = null;

    /** @var (callable(Uuid): void)|null runs after each read of the stored status, before the caller sees it; after a guarded save, it runs once the save is done */
    public $afterStatusRead = null;

    public function create(bool $isFull, ?Uuid $userId = null, array $metadata = [], ?Uuid $originalJobId = null): RecommendationJob
    {
        $job = RecommendationJob::create($isFull, $userId, $metadata, $originalJobId);
        $this->save($job);

        return $job;
    }

    public function getById(Uuid $id): ?RecommendationJob
    {
        return $this->jobs[$id->toString()] ?? null;
    }

    public function getByPublicId(PublicId $publicId): ?RecommendationJob
    {
        foreach ($this->jobs as $job) {
            if ($job->getPublicId()->equals($publicId)) {
                return $job;
            }
        }

        return null;
    }

    public function findRecent(int $limit = 20, ?string $status = null): array
    {
        $jobs = array_filter($this->jobs, static fn (RecommendationJob $job): bool => $status === null || $job->getStatus()->value === $status);

        return array_slice(array_values($jobs), 0, $limit);
    }

    public function save(RecommendationJob $job): void
    {
        $this->jobs[$job->getId()->toString()] = $job;
        $this->storedStatus[$job->getId()->toString()] = $job->getStatus();
        $this->savedStatuses[] = $job->getStatus()->value;
        if ($this->onSave !== null) {
            ($this->onSave)($job);
        }
    }

    public function saveIfStatusIn(RecommendationJob $job, RecommendationJobStatus ...$from): bool
    {
        $saved = in_array($this->storedStatus[$job->getId()->toString()] ?? null, $from, true);
        if ($saved) {
            $this->save($job);
        }
        // The read and the write are one step; another process can only act after both.
        $this->afterStatusRead($job->getId());

        return $saved;
    }

    public function storedStatus(Uuid $id): ?RecommendationJobStatus
    {
        $status = $this->storedStatus[$id->toString()] ?? null;
        $this->afterStatusRead($id);

        return $status;
    }

    private function afterStatusRead(Uuid $id): void
    {
        if ($this->afterStatusRead !== null) {
            ($this->afterStatusRead)($id);
        }
    }
}
