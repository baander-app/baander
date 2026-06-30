<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Repository\TranscodeSessionRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\Exception\SessionNotFoundException;

final class TranscodeSessionService implements TranscodeSessionPortInterface
{
    public function __construct(
        private readonly TranscodeSessionRepositoryInterface $sessionRepository,
    ) {
    }

    public function createSession(
        Uuid $userId,
        Uuid $jobId,
        Uuid $videoId,
        AudioProfile $audioProfile,
        SessionPriority $priority = SessionPriority::Normal,
        array $audioLanguages = [],
    ): TranscodeSession {
        $session = TranscodeSession::create(
            $userId,
            $jobId,
            $videoId,
            $audioProfile,
            $priority,
            $audioLanguages,
        );

        $this->sessionRepository->save($session);

        return $session;
    }

    public function findByUuid(Uuid $uuid): ?TranscodeSession
    {
        return $this->sessionRepository->findByUuid($uuid);
    }

    public function findByPublicId(\App\Shared\Domain\Model\PublicId $publicId): ?TranscodeSession
    {
        return $this->sessionRepository->findByPublicId($publicId);
    }

    public function findActiveByUser(Uuid $userId): array
    {
        return $this->sessionRepository->findActiveSessions($userId);
    }

    public function cancelSession(Uuid $uuid): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session === null) {
            throw SessionNotFoundException::forId($uuid);
        }

        $session->markCancelled();
        $this->sessionRepository->save($session);
    }

    public function pauseSession(Uuid $uuid): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session === null) {
            throw SessionNotFoundException::forId($uuid);
        }

        $session->markPaused();
        $this->sessionRepository->save($session);
    }

    public function resumeSession(Uuid $uuid): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session === null) {
            throw SessionNotFoundException::forId($uuid);
        }

        $session->markResumed();
        $this->sessionRepository->save($session);
    }

    public function updateAudioProfile(Uuid $uuid, AudioProfile $profile): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session === null) {
            throw SessionNotFoundException::forId($uuid);
        }

        $session->updateAudioProfile($profile);
        $this->sessionRepository->save($session);
    }

    public function save(TranscodeSession $session): void
    {
        $this->sessionRepository->save($session);
    }
}
