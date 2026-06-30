<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Repository;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeSession;

interface TranscodeSessionRepositoryInterface
{
    public function save(TranscodeSession $session): void;

    public function persist(TranscodeSession $session): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?TranscodeSession;

    public function findByPublicId(PublicId $publicId): ?TranscodeSession;

    /** @return TranscodeSession[] */
    public function findByUser(Uuid $userId): array;

    /** @return TranscodeSession[] */
    public function findByJob(Uuid $jobId): array;

    /** @return TranscodeSession[] */
    public function findActiveSessions(Uuid $userId): array;

    public function count(): int;

    public function delete(TranscodeSession $session): void;
}
