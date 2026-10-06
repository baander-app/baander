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
