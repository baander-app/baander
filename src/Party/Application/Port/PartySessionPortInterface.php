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
        ?Uuid $transcodeJobId,
        int $maxMembers = 10,
    ): SyncedPartySession;

    public function findByUuid(Uuid $uuid): ?SyncedPartySession;

    /**
     * Locks the party's row inside the caller's transaction and returns the session as it is
     * stored then, or null when it does not exist.
     *
     * A change of a party's members or host starts here: two of them then take turns, and the
     * second decides on what the first committed, not on a session loaded before the lock.
     */
    public function lockByUuid(Uuid $uuid): ?SyncedPartySession;

    public function findByPublicId(PublicId $publicId): ?SyncedPartySession;

    public function findByPublicIdentifier(string $publicId): ?SyncedPartySession;

    /**
     * Active sessions the user belongs to, as host or member, oldest first.
     *
     * @return SyncedPartySession[]
     */
    public function findActiveSessionsForMember(Uuid $userId): array;

    /** @return SyncedPartySession[] */
    public function findByVideo(Uuid $videoId): array;

    public function startPlayback(Uuid $uuid, ?float $position = null): void;

    public function pausePlayback(Uuid $uuid): void;

    public function seekTo(Uuid $uuid, float $position): void;

    public function syncPlayback(Uuid $uuid, float $clientPosition, float $clientLatency): float;

    public function endSession(Uuid $uuid): void;

    public function save(SyncedPartySession $session): void;

    public function delete(SyncedPartySession $session): void;
}
