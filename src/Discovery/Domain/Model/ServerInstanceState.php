<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Model;

use App\Discovery\Domain\ValueObject\ServerStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for ServerInstance aggregate root.
 */
final class ServerInstanceState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly string $serverUrl,
        public readonly string $name,
        public readonly string $apiKey,
        public readonly DateTimeImmutable $createdAt,
        public string $version,
        public ServerStatus $status = ServerStatus::Online,
        public ?DateTimeImmutable $lastHeartbeatAt = null,
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
