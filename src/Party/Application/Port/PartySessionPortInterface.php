<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface PartySessionPortInterface
{
    public function createSession(
        Uuid $hostUserId,
        Uuid $videoId,
        Uuid $transcodeJobId,
        int $maxMembers = 10,
    ): SyncedPartySession;

    public function findByUuid(Uuid $uuid): ?SyncedPartySession;

    public function findByPublicId(PublicId $publicId): ?SyncedPartySession;

    /** @return SyncedPartySession[] */
    public function findActiveSessions(): array;

    /** @return SyncedPartySession[] */
    public function findByVideo(Uuid $videoId): array;

    public function startPlayback(Uuid $uuid, ?float $position = null): void;

    public function pausePlayback(Uuid $uuid): void;

    public function seekTo(Uuid $uuid, float $position): void;

    public function syncPlayback(Uuid $uuid, float $clientPosition, float $clientLatency): float;

    public function endSession(Uuid $uuid): void;

    public function transferHost(Uuid $uuid, Uuid $newHostUserId): void;

    public function save(SyncedPartySession $session): void;

    public function delete(SyncedPartySession $session): void;
}
