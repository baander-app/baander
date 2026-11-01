<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Swoole;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Application\Port\WorkerTimerInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use Psr\Log\LoggerInterface;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PlaybackCommandRelay implements EventSubscriberInterface, WorkerTimerInterface, \App\Session\Application\Port\PlaybackConnectionsInterface
{
    /** @var array<int,array{owner:Uuid,incarnation:?string,capability:?string,digest:?string,generation:int}> */
    private array $connections = [];
    private ?int $timerId = null;
    private bool $ticking = false;
    private int $bindingGeneration = 0;

    public function __construct(
        private readonly PlaybackCoordinationInterface $playback,
        private readonly WebSocketPusher $pusher,
        private readonly CoWrapper $coWrapper,
        private readonly ServerDrainStateInterface $drain,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::NAME => 'onWorkerStarted', WorkerStoppedEvent::NAME => 'onWorkerStopped'];
    }

    public function bind(int $fd, Uuid $owner, ?string $incarnation = null, ?string $capability = null): void
    {
        $previous = $this->connections[$fd] ?? null;
        if ($previous !== null && !$previous['owner']->equals($owner)) {
            $previous = null;
        }
        $incarnation ??= $previous['incarnation'] ?? null;
        $capability ??= $previous['capability'] ?? null;
        $sameAuthority = $previous !== null && $previous['incarnation'] === $incarnation && $previous['capability'] === $capability;
        $this->connections[$fd] = [
            'owner' => $owner,
            'incarnation' => $incarnation,
            'capability' => $capability,
            'digest' => $sameAuthority ? $previous['digest'] : null,
            'generation' => ++$this->bindingGeneration,
        ];
    }

    public function unbind(int $fd): void
    {
        unset($this->connections[$fd]);
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($event->getServer()->taskworker || $this->timerId !== null) {
            return;
        }
        $id = Timer::tick(1000, $this->tick(...));
        if ($id < 1) {
            throw new \RuntimeException('Cannot start playback command replay.');
        }
        $this->timerId = $id;
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->stop();
    }

    public function stop(): void
    {
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
        }
        $this->timerId = null;
        $this->connections = [];
    }

    public function tick(): void
    {
        $this->coWrapper->defer();
        if ($this->ticking || $this->drain->isDraining()) {
            return;
        }
        $this->ticking = true;
        try {
            foreach ($this->connections as $fd => $connection) {
                try {
                    $input = $connection['incarnation'] === null ? [] : ['incarnationId' => $connection['incarnation']];
                    $snapshot = $this->playback->execute($connection['owner'], PlaybackOperation::Snapshot, $input, $connection['capability']);
                    if (!$this->isCurrentBinding($fd, $connection['generation'])) {
                        continue;
                    }
                    $digest = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
                    if ($connection['digest'] !== $digest || $snapshot['commands'] !== []) {
                        if (!$this->pusher->pushToConnection($fd, ['type' => 'session.snapshot', 'data' => $snapshot])) {
                            continue;
                        }
                        $this->recordDigestIfCurrent($fd, $connection['generation'], $digest);
                    }
                } catch (\Throwable $error) {
                    $this->logger->error('Playback command replay failed; committed commands remain available for retry.', ['exception' => $error]);
                }
            }
        } finally {
            $this->ticking = false;
        }
    }
    private function isCurrentBinding(int $fd, int $generation): bool
    {
        return ($this->connections[$fd]['generation'] ?? null) === $generation;
    }
    private function recordDigestIfCurrent(int $fd, int $generation, string $digest): void
    {
        if ($this->isCurrentBinding($fd, $generation)) {
            $this->connections[$fd]['digest'] = $digest;
        }
    }

}
