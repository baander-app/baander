<?php

declare(strict_types=1);

namespace App\Party\Domain\Repository;

use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface SyncedPartySessionRepositoryInterface
{
    public function save(SyncedPartySession $session): void;

    public function persist(SyncedPartySession $session): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?SyncedPartySession;

    /**
     * Locks the session's row until the caller's transaction ends and returns the session as it
     * is stored then, or null when it does not exist. A session loaded earlier through the same
     * unit of work is overwritten with the locked row. Refused outside a transaction, where the
     * lock would end with the statement.
     */
    public function lockByUuid(Uuid $uuid): ?SyncedPartySession;

    public function findByPublicId(PublicId $publicId): ?SyncedPartySession;

    /**
     * Active sessions the user belongs to, as host or member, oldest first.
     *
     * @return SyncedPartySession[]
     */
    public function findActiveSessionsForMember(Uuid $userId): array;

    /**
     * @return SyncedPartySession[]
     */
    public function findByVideo(Uuid $videoId): array;

    public function delete(SyncedPartySession $session): void;
}
