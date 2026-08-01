<?php

declare(strict_types=1);

namespace App\Discovery\Application\Command;

use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Shared\Domain\Model\PublicId;

final readonly class CreatePairingCodeCommand
{
    public function __construct(
        private PublicId $serverPublicId,
        private AuthenticationMethod $method,
    ) {
    }

    public function getServerPublicId(): PublicId { return $this->serverPublicId; }
    public function getMethod(): AuthenticationMethod { return $this->method; }
}
