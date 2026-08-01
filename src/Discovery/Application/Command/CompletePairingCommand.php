<?php

declare(strict_types=1);

namespace App\Discovery\Application\Command;

use App\Shared\Domain\Model\PublicId;

final readonly class CompletePairingCommand
{
    public function __construct(
        private string $pairingCode,
        private PublicId $serverPublicId,
    ) {
    }

    public function getPairingCode(): string { return $this->pairingCode; }
    public function getServerPublicId(): PublicId { return $this->serverPublicId; }
}
