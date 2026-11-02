<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Messaging;

use App\Session\Application\Port\PlaybackConnectionsInterface;
use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Shared\Application\Port\ListeningSessionInteractionInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class ListeningSessionInteractionAdapter implements ListeningSessionInteractionInterface
{
    public function __construct(
        private PlaybackCoordinationInterface $coordination,
        private PlaybackConnectionsInterface $relay,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function coordinate(Uuid $userId, string $operation, array $input, ?string $endpointCapability = null): array
    {
        return $this->coordination->execute($userId, PlaybackOperation::from($operation), $input, $endpointCapability);
    }

    public function bindPlayback(int $connectionId, Uuid $userId, ?string $incarnationId = null, ?string $endpointCapability = null): void
    {
        $this->relay->bind($connectionId, $userId, $incarnationId, $endpointCapability);
    }

    public function disconnectPlayback(int $connectionId): void
    {
        $this->relay->unbind($connectionId);
    }
}
