<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveMessage;
use App\Shared\Application\Port\WorkerTimerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class LiveFrameSender implements EventSubscriberInterface, WorkerTimerInterface
{
    private const string OVERLOAD_REASON = 'Live delivery overloaded';

    private ?Server $server = null;
    private int $slowPeerCloseCount = 0;
    /** @var array<int, \stdClass> */
    private array $sending = [];
    /** @var array<int, LiveMessage> */
    private array $pending = [];
    /** @var array<int, int> */
    private array $watchdogs = [];

    public function __construct(private readonly LoggerInterface $logger, private readonly CoWrapper $coWrapper) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::NAME => 'onWorkerStarted'];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->setServer($event->getServer());
    }

    public function setServer(\Swoole\Server $server): void
    {
        $this->server = $server instanceof Server ? $server : null;
    }

    public function send(int $fd, LiveMessage $message): bool
    {
        if ($this->server === null || !$this->server->isEstablished($fd)) {
            return false;
        }
        if (isset($this->sending[$fd])) {
            $previous = $this->pending[$fd] ?? null;
            $this->pending[$fd] = $previous === null ? $message : $this->merge($previous, $message);
            return true;
        }
        $token = new \stdClass();
        $this->sending[$fd] = $token;
        $coroutine = Coroutine::create(function () use ($fd, $message, $token): void {
            $this->coWrapper->defer();
            try {
                $next = $message;
                do {
                    if (($this->sending[$fd] ?? null) !== $token) {
                        break;
                    }
                    unset($this->pending[$fd]);
                    if (!$this->push($fd, $next)) {
                        break;
                    }
                    $next = $this->pending[$fd] ?? null;
                } while ($next !== null);
            } finally {
                if (($this->sending[$fd] ?? null) === $token) {
                    $this->forget($fd);
                }
            }
        });
        if ($coroutine === false) {
            $this->close($fd, 1013, 'Live delivery unavailable');
            return false;
        }
        return true;
    }

    /** @return array{pending_send_count: int, slow_peer_close_count: int} */
    public function diagnostics(): array
    {
        return [
            'pending_send_count' => count($this->pending),
            'slow_peer_close_count' => $this->slowPeerCloseCount,
        ];
    }

    public function stop(): void
    {
        foreach ($this->watchdogs as $fd => $timer) {
            Timer::clear($timer);
            $this->server?->close($fd, true);
        }
        $this->watchdogs = [];
        $this->sending = [];
        $this->pending = [];
    }

    public function forget(int $fd): void
    {
        unset($this->sending[$fd], $this->pending[$fd]);
    }

    public function close(int $fd, int $code, string $reason): void
    {
        if ($code === 1013 && $reason === self::OVERLOAD_REASON) {
            ++$this->slowPeerCloseCount;
        }
        $this->logger->warning('Live connection closed.', ['fd' => $fd, 'code' => $code, 'reason' => $reason]);
        $this->forget($fd);
        $this->server?->close($fd, true);
    }

    private function push(int $fd, LiveMessage $message): bool
    {
        $server = $this->server;
        if ($server === null || !$server->isEstablished($fd)) {
            return false;
        }
        $data = json_encode($message->toArray(), JSON_THROW_ON_ERROR);
        $queued = $server->getClientInfo($fd)['send_queued_bytes'] ?? 0;
        if (strlen($data) > LiveMessage::MAX_BYTES || $queued + strlen(Server::pack($data)) > LiveMessage::MAX_BYTES) {
            $this->close($fd, 1013, self::OVERLOAD_REASON);
            return false;
        }
        // A native push can yield on an IPC buffer race despite the queue preflight.
        // Closing that FD releases its native send waiter without changing media sends.
        $expired = false;
        $token = $this->sending[$fd] ?? null;
        $timer = Timer::after(100, function () use ($fd, &$expired, $token): void {
            $this->coWrapper->defer();
            $expired = true;
            if (($this->sending[$fd] ?? null) === $token) {
                $this->close($fd, 1013, self::OVERLOAD_REASON);
            }
        });
        if ($timer < 1) {
            $this->close($fd, 1013, 'Live delivery unavailable');
            return false;
        }
        $this->watchdogs[$fd] = $timer;
        try {
            $sent = $server->push($fd, $data);
            if (($this->sending[$fd] ?? null) !== $token) {
                return false;
            }
            if (!$sent || $expired) {
                $this->close($fd, 1013, self::OVERLOAD_REASON);
                return false;
            }
            return true;
        } finally {
            if (($this->watchdogs[$fd] ?? null) === $timer) {
                unset($this->watchdogs[$fd]);
            }
            if (!$expired) {
                Timer::clear($timer);
            }
        }
    }

    private function merge(LiveMessage $previous, LiveMessage $next): LiveMessage
    {
        $old = $previous->toArray();
        $new = $next->toArray();
        if ($old['subscriptionId'] !== $new['subscriptionId']) {
            return $next;
        }
        $new['families'] = array_values(array_unique([...$old['families'], ...$new['families']]));
        if ($old['authorizationGeneration'] !== $new['authorizationGeneration']) {
            $new['type'] = 'live.reset';
            return LiveMessage::fromArray($new);
        }
        $priority = ['live.health' => 0, 'live.changed' => 1, 'live.reset' => 2, 'live.ready' => 3];
        if ($priority[$old['type']] > $priority[$new['type']]) {
            $new['type'] = $old['type'];
        }
        return LiveMessage::fromArray($new);
    }
}
