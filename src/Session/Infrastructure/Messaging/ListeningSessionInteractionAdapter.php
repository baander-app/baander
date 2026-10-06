<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Messaging;

use App\Session\Application\Command\SessionJoinCommand;
use App\Session\Application\Command\SessionPlaybackCommand;
use App\Session\Application\Command\SyncSessionCommand;
use App\Shared\Application\Port\ListeningSessionInteractionInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class ListeningSessionInteractionAdapter implements ListeningSessionInteractionInterface
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function join(Uuid $userId, Uuid $deviceId): mixed
    {
        $envelope = $this->bus->dispatch(new SessionJoinCommand($userId, $deviceId));

        return $envelope->last(HandledStamp::class)?->getResult() ?? [];
    }

    /** @param array<array-key, mixed>|null $queue */
    public function playback(
        Uuid $userId,
        Uuid $deviceId,
        string $action,
        ?float $position = null,
        ?array $queue = null,
        ?int $currentTrackIndex = null,
        ?string $playbackState = null,
    ): mixed {
        $envelope = $this->bus->dispatch(new SessionPlaybackCommand(
            $userId, $deviceId, $action, $position, $queue, $currentTrackIndex, $playbackState,
        ));

        return $envelope->last(HandledStamp::class)?->getResult() ?? [];
    }

    /** @param array<array-key, mixed> $queue */
    public function sync(
        Uuid $userId,
        Uuid $deviceId,
        array $queue,
        int $currentTrackIndex,
        float $position,
        string $playbackState,
    ): mixed {
        $envelope = $this->bus->dispatch(new SyncSessionCommand(
            $userId, $deviceId, $queue, $currentTrackIndex, $position, $playbackState,
        ));

        return $envelope->last(HandledStamp::class)?->getResult() ?? [];
    }
}
