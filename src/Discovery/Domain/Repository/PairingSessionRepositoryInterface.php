<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Repository;

use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\Uuid;

interface PairingSessionRepositoryInterface
{
    public function save(PairingSession $session): void;

    public function persist(PairingSession $session): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?PairingSession;

    public function findByPairingCode(PairingCode $code): ?PairingSession;

    public function findPendingByServer(Uuid $serverId): ?PairingSession;

    public function delete(PairingSession $session): void;
}
