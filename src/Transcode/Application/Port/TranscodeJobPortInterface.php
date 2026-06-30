<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;

interface TranscodeJobPortInterface
{
    public function getOrCreateJob(
        Uuid $videoId,
        QualityTier $qualityTier,
        string $outputDirectory,
        array $audioTrackLanguages = [],
    ): TranscodeJob;

    public function findByUuid(Uuid $uuid): ?TranscodeJob;

    public function findByPublicId(PublicId $publicId): ?TranscodeJob;

    /** @return TranscodeJob[] */
    public function findOrphanedJobs(): array;

    /** @return TranscodeJob[] */
    public function findInProgressJobs(): array;

    public function cleanupOrphanedJobs(): int;

    public function save(TranscodeJob $job): void;
}
