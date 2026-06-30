<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Repository;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;

interface TranscodeJobRepositoryInterface
{
    public function save(TranscodeJob $job): void;

    public function persist(TranscodeJob $job): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?TranscodeJob;

    public function findByPublicId(PublicId $publicId): ?TranscodeJob;

    public function findByVideoAndQuality(Uuid $videoId, string $qualityTierName): ?TranscodeJob;

    /** @return TranscodeJob[] */
    public function findActiveByVideo(Uuid $videoId): array;

    /** @return TranscodeJob[] */
    public function findOrphanedJobs(): array;

    /** @return TranscodeJob[] */
    public function findInProgressJobs(): array;

    public function delete(TranscodeJob $job): void;
}
