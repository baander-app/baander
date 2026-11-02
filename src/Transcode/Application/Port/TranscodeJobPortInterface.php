<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\PresentationSelection;

interface TranscodeJobPortInterface
{
    /**
     * @param array<array-key, string> $audioTrackLanguages
     */
    public function getOrCreateJob(
        Uuid $videoId,
        QualityTier $qualityTier,
        string $outputDirectory,
        Uuid $ownerUserId,
        array $audioTrackLanguages = [],
        ?PresentationSelection $presentationSelection = null,
        ?string $requestId = null,
    ): TranscodeJob;

    public function getOrCreateCopyJob(
        Uuid $videoId,
        string $outputDirectory,
        Uuid $ownerUserId,
        PresentationSelection $presentationSelection,
        int $width,
        int $height,
        ?string $requestId = null,
    ): TranscodeJob;

    public function findByUuid(Uuid $uuid): ?TranscodeJob;

    public function findByPublicId(PublicId $publicId): ?TranscodeJob;

    /** @return TranscodeJob[] */
    public function findOrphanedJobs(): array;

    /** @return TranscodeJob[] */
    public function findInProgressJobs(): array;

    /** @return TranscodeJob[] */
    public function findActiveByOwnerAndVideo(Uuid $ownerUserId, Uuid $videoId): array;

    /** @return TranscodeJob[] */
    public function findActiveByVideo(Uuid $videoId): array;

    public function cleanupOrphanedJobs(): int;

    public function save(TranscodeJob $job): void;
}
