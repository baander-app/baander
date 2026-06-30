<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;

interface TranscodeSessionPortInterface
{
    public function createSession(
        Uuid $userId,
        Uuid $jobId,
        Uuid $videoId,
        AudioProfile $audioProfile,
        SessionPriority $priority = SessionPriority::Normal,
        array $audioLanguages = [],
    ): TranscodeSession;

    public function findByUuid(Uuid $uuid): ?TranscodeSession;

    public function findByPublicId(PublicId $publicId): ?TranscodeSession;

    /** @return TranscodeSession[] */
    public function findActiveByUser(Uuid $userId): array;

    public function cancelSession(Uuid $uuid): void;

    public function pauseSession(Uuid $uuid): void;

    public function resumeSession(Uuid $uuid): void;

    public function updateAudioProfile(Uuid $uuid, AudioProfile $profile): void;

    public function save(TranscodeSession $session): void;
}
