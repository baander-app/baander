<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Port;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
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
     * Saves the job only while its stored status is one of $from. The status is read and the
     * job written under a row lock, so a status another process stored meanwhile, such as an
     * admin's cancellation, is never overwritten.
     *
     * @return bool false when the stored status is not one of $from, or the job is not stored; nothing was saved
     */
    public function saveIfStatusIn(RecommendationJob $job, RecommendationJobStatus ...$from): bool;

    /**
     * Reads the stored status, bypassing any copy of the job this process holds, so a run
     * sees a cancellation made from another process. Null for an unknown job.
     */
    public function storedStatus(Uuid $id): ?RecommendationJobStatus;
}
