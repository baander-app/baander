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

    public function findByPublicId(PublicId $publicId): ?SyncedPartySession;

    /**
     * @return SyncedPartySession[]
     */
    public function findActiveSessions(): array;

    /**
     * @return SyncedPartySession[]
     */
    public function findByVideo(Uuid $videoId): array;

    public function delete(SyncedPartySession $session): void;
}
