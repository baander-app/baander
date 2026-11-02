<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface ListeningSessionInteractionInterface
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function coordinate(Uuid $userId, string $operation, array $input, ?string $endpointCapability = null): array;

    public function bindPlayback(int $connectionId, Uuid $userId, ?string $incarnationId = null, ?string $endpointCapability = null): void;

    public function disconnectPlayback(int $connectionId): void;

}
