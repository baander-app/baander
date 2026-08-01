<?php

declare(strict_types=1);

namespace App\Discovery\Application\Port;

use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface DiscoveryPortInterface
{
    public function createPairingSession(
        Uuid $serverId,
        PublicId $serverPublicId,
        string $serverUrl,
        string $serverName,
        AuthenticationMethod $method,
    ): PairingSession;

    public function completePairing(PairingSession $session): void;

    public function findByPairingCode(string $pairingCode): ?PairingSession;
}
