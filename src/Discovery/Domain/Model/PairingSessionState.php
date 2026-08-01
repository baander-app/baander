<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Model;

use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for PairingSession aggregate root.
 */
final class PairingSessionState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $serverId,
        public readonly PublicId $serverPublicId,
        public readonly string $serverUrl,
        public readonly string $serverName,
        public readonly PairingCode $pairingCode,
        public readonly AuthenticationMethod $method,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $expiredAt = null,
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
