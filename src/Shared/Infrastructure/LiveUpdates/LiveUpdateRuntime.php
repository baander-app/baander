<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveAccessDenied;
use App\Shared\Application\LiveUpdates\LiveChangeProjector;
use App\Shared\Application\LiveUpdates\LiveMessage;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Application\LiveUpdates\LiveSubscriptions;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Application\Port\WorkerTimerInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use Psr\Log\LoggerInterface;
use Swoole\Timer;
use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @phpstan-type LiveRuntimeDiagnostics array{
 *     projector_enabled: bool,
 *     last_successful_projection_check_age_ms: int|null,
 *     last_successful_reconciliation_age_ms: int|null,
 *     max_subscription_read_age_ms: int|null,
 *     backlog_observed: bool,
 *     last_observed_oldest_pending_age_ms: int|null,
 *     projection_error_count: int,
 *     read_error_count: int,
 *     active_subscriptions: int,
 *     pending_send_count: int,
 *     slow_peer_close_count: int
 * }
 */
final class LiveUpdateRuntime implements LiveSubscriptions, EventSubscriberInterface, WorkerTimerInterface
{
    /** @var array<int, LiveConnectionState> */
    private array $connections = [];
    /** @var list<int> */
    private array $timers = [];
    private bool $reconciling = false;
    private bool $projecting = false;
    private bool $publicationFailed = false;
    private bool $stopped = false;
    private int $cursor = 0;
    private bool $projectorEnabled = false;
    private ?float $lastSuccessfulProjectionCheck = null;
    private ?float $lastSuccessfulReconciliation = null;
    private bool $backlogObserved = false;
    private ?int $lastObservedOldestPendingAgeMs = null;
    private int $projectionErrorCount = 0;
    private int $readErrorCount = 0;
    /** @var array<string, true> */
    private array $reading = [];

    public function __construct(
        private readonly LiveSnapshotReader $reader,
        private readonly LiveChangeProjector $projector,
        private readonly WebSocketConnectionRegistry $registry,
        private readonly LiveFrameSender $sender,
        private readonly LoggerInterface $logger,
        private readonly CoWrapper $coWrapper,
        private readonly ServerDrainStateInterface $drain,
        private readonly ServicePoolContainer $servicePools,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::NAME => 'onWorkerStarted', WorkerStoppedEvent::NAME => 'onWorkerStopped'];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($event->getServer()->taskworker || $this->timers !== []) {
            return;
        }
        $this->stopped = false;
        $this->startTimer(1000, $this->reconcile(...));
        if ($event->getWorkerId() === 0) {
            $this->projectorEnabled = $this->startTimer(250, $this->project(...));
        }
    }

    private function startTimer(int $interval, \Closure $callback): bool
    {
        $timer = Timer::tick($interval, $callback);
        if ($timer < 1) {
            $this->logger->error('Live runtime could not start its {interval}ms timer.', ['interval' => $interval]);
            return false;
        }
        $this->timers[] = $timer;
        return true;
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->stop();
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->projectorEnabled = false;
        foreach ($this->timers as $timer) {
            Timer::clear($timer);
        }
        $this->timers = [];
        $this->connections = [];
        $this->sender->stop();
    }

    /**
     * A successful projection check includes empty and busy projector returns; it
     * does not assert that revisions advanced or that this worker acquired the lock.
     * Backlog is the last successful snapshot observation, not a fresh database query.
     * When backlog_observed is false its null age means unknown; once true, null means
     * that observation found no pending inbox rows. Counts last for this worker process.
     *
     * @return LiveRuntimeDiagnostics
     */
    public function diagnostics(): array
    {
        $maxReadAge = null;
        foreach ($this->connections as $state) {
            $age = $state->successfulReadAgeMs();
            if ($age !== null) {
                $maxReadAge = max($maxReadAge ?? 0, $age);
            }
        }
        return [
            'projector_enabled' => $this->projectorEnabled,
            'last_successful_projection_check_age_ms' => self::ageMs($this->lastSuccessfulProjectionCheck),
            'last_successful_reconciliation_age_ms' => self::ageMs($this->lastSuccessfulReconciliation),
            'max_subscription_read_age_ms' => $maxReadAge,
            'backlog_observed' => $this->backlogObserved,
            'last_observed_oldest_pending_age_ms' => $this->lastObservedOldestPendingAgeMs,
            'projection_error_count' => $this->projectionErrorCount,
            'read_error_count' => $this->readErrorCount,
            'active_subscriptions' => count($this->connections),
            ...$this->sender->diagnostics(),
        ];
    }

    private static function ageMs(?float $timestamp): ?int
    {
        return $timestamp === null ? null : max(0, (int) ((microtime(true) - $timestamp) * 1000));
    }

    public function subscribe(int $fd, Uuid $principal): void
    {
        if ($this->stopped || $this->drain->isDraining() || !$this->owns($fd, $principal)) {
            return;
        }
        if (isset($this->connections[$fd])) {
            return;
        }
        // Register observation before the snapshot can yield to another coroutine.
        $this->connections[$fd] = new LiveConnectionState($principal);
        $this->observe($principal, [$fd => $this->connections[$fd]]);
    }

    public function unsubscribe(int $fd): void
    {
        unset($this->connections[$fd]);
        $this->sender->forget($fd);
    }

    public function project(): void
    {
        $this->coWrapper->defer();
        if ($this->stopped || $this->drain->isDraining() || $this->projecting) {
            return;
        }
        $this->projecting = true;
        try {
            try {
                $this->projector->project();
            } finally {
                $this->servicePools->releaseFromCoroutine(Coroutine::getCid());
            }
            $this->publicationFailed = false;
            $this->lastSuccessfulProjectionCheck = microtime(true);
        } catch (\Throwable $error) {
            $this->publicationFailed = true;
            ++$this->projectionErrorCount;
            $this->logger->error('Live revision projection failed; committed changes remain pending.', ['exception' => $error]);
            foreach ($this->connections as $fd => $state) {
                if (($health = $state->failed()) !== null) {
                    $this->deliver($fd, $health);
                }
            }
        } finally {
            $this->projecting = false;
        }
    }

    public function reconcile(): void
    {
        $this->coWrapper->defer();
        if ($this->stopped || $this->drain->isDraining() || $this->reconciling) {
            return;
        }
        $this->reconciling = true;
        try {
            $principals = [];
            foreach ($this->connections as $fd => $state) {
                if (!$this->owns($fd, $state->principal)) {
                    $this->unsubscribe($fd);
                    continue;
                }
                $principals[$state->principal->toString()][$fd] = $state;
            }
            $keys = array_keys($principals);
            $count = count($keys);
            for ($n = 0; $n < min(32, $count); ++$n) {
                $key = $keys[($this->cursor + $n) % $count];
                $this->observe(Uuid::fromString($key), $principals[$key]);
            }
            foreach ($this->connections as $fd => $state) {
                if (($health = $state->heartbeat()) !== null) {
                    $this->deliver($fd, $health);
                }
            }
            $this->cursor = $count === 0 ? 0 : ($this->cursor + min(32, $count)) % $count;
        } finally {
            $this->reconciling = false;
        }
    }

    /** @param array<int, LiveConnectionState> $states */
    private function observe(Uuid $principal, array $states): void
    {
        $key = $principal->toString();
        if (isset($this->reading[$key])) {
            return;
        }
        $this->reading[$key] = true;
        try {
            try {
                $snapshot = $this->reader->read($principal);
            } finally {
                $this->servicePools->releaseFromCoroutine(Coroutine::getCid());
            }
        } catch (LiveAccessDenied) {
            foreach ($states as $fd => $state) {
                if (($this->connections[$fd] ?? null) === $state) {
                    $this->unsubscribe($fd);
                    $this->sender->close($fd, 1008, 'Live authorization ended');
                }
            }
            return;
        } catch (\Throwable $error) {
            ++$this->readErrorCount;
            $this->logger->error('Live authorization or revision read failed; the prior baseline is retained.', ['exception' => $error]);
            foreach ($states as $fd => $state) {
                if (($this->connections[$fd] ?? null) === $state && ($health = $state->failed()) !== null) {
                    $this->deliver($fd, $health);
                }
            }
            return;
        } finally {
            unset($this->reading[$key]);
        }
        $this->lastSuccessfulReconciliation = microtime(true);
        $this->backlogObserved = true;
        $this->lastObservedOldestPendingAgeMs = $snapshot->health->oldestPendingAgeMs;
        foreach ($states as $fd => $state) {
            if (($this->connections[$fd] ?? null) !== $state || !$this->owns($fd, $principal)) {
                continue;
            }
            foreach ($state->observe($snapshot, $this->publicationFailed) as $frame) {
                $this->deliver($fd, $frame);
            }
        }
    }

    private function deliver(int $fd, LiveMessage $frame): void
    {
        if (!$this->sender->send($fd, $frame)) {
            $this->unsubscribe($fd);
        }
    }

    private function owns(int $fd, Uuid $principal): bool
    {
        $connection = $this->registry->getConnection($fd);
        return $connection !== null
            && $connection['worker_id'] === $this->registry->getWorkerId()
            && $connection['user_id'] === $principal->toString();
    }
}
