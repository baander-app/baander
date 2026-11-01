<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Results mirror the synchronous Messenger handler result, with [] for no result.
 * A concrete Session response contract can be introduced separately.
 */
interface ListeningSessionInteractionInterface
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function coordinate(Uuid $userId, string $operation, array $input, ?string $endpointCapability = null): array;

    public function bindPlayback(int $connectionId, Uuid $userId, ?string $incarnationId = null, ?string $endpointCapability = null): void;

    public function disconnectPlayback(int $connectionId): void;

    public function join(Uuid $userId, Uuid $deviceId): mixed;

    /** @param array<array-key, mixed>|null $queue */
    public function playback(
        Uuid $userId,
        Uuid $deviceId,
        string $action,
        ?float $position = null,
        ?array $queue = null,
        ?int $currentTrackIndex = null,
        ?string $playbackState = null,
    ): mixed;

    /** @param array<array-key, mixed> $queue */
    public function sync(
        Uuid $userId,
        Uuid $deviceId,
        array $queue,
        int $currentTrackIndex,
        float $position,
        string $playbackState,
    ): mixed;
}
