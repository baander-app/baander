<?php

declare(strict_types=1);

namespace App\Party\Infrastructure;

use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\Repository\SyncedPartySessionRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class PartySessionService implements PartySessionPortInterface
{
    public function __construct(
        private SyncedPartySessionRepositoryInterface $sessionRepository,
    ) {
    }

    public function createSession(Uuid $hostUserId, Uuid $videoId, Uuid $transcodeJobId, int $maxMembers = 10): SyncedPartySession
    {
        $session = SyncedPartySession::create($hostUserId, $videoId, $transcodeJobId, $maxMembers);
        $this->sessionRepository->save($session);

        return $session;
    }

    public function findByUuid(Uuid $uuid): ?SyncedPartySession
    {
        return $this->sessionRepository->findByUuid($uuid);
    }

    public function findByPublicId(PublicId $publicId): ?SyncedPartySession
    {
        return $this->sessionRepository->findByPublicId($publicId);
    }

    public function findActiveSessions(): array
    {
        return $this->sessionRepository->findActiveSessions();
    }

    public function findByVideo(Uuid $videoId): array
    {
        return $this->sessionRepository->findByVideo($videoId);
    }

    public function startPlayback(Uuid $uuid, ?float $position = null): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session !== null) {
            $session->startPlayback($position);
            $this->sessionRepository->save($session);
        }
    }

    public function pausePlayback(Uuid $uuid): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session !== null) {
            $session->pausePlayback();
            $this->sessionRepository->save($session);
        }
    }

    public function seekTo(Uuid $uuid, float $position): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session !== null) {
            $session->seekTo($position);
            $this->sessionRepository->save($session);
        }
    }

    public function syncPlayback(Uuid $uuid, float $clientPosition, float $clientLatency): float
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session === null) {
            return 0.0;
        }

        return $session->syncPlayback($clientPosition, $clientLatency);
    }

    public function endSession(Uuid $uuid): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session !== null) {
            $session->endSession();
            $this->sessionRepository->save($session);
        }
    }

    public function transferHost(Uuid $uuid, Uuid $newHostUserId): void
    {
        $session = $this->sessionRepository->findByUuid($uuid);
        if ($session !== null) {
            $session->transferHost($newHostUserId);
            $this->sessionRepository->save($session);
        }
    }

    public function save(SyncedPartySession $session): void
    {
        $this->sessionRepository->save($session);
    }

    public function delete(SyncedPartySession $session): void
    {
        $this->sessionRepository->delete($session);
    }
}
