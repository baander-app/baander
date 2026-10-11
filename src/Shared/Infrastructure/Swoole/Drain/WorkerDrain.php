<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use Psr\Log\LoggerInterface;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * An event worker's part of the drain. Each event worker polls the drain flag on a short tick of
 * its own. The first tick that sees it runs the drain steps once, while the master still holds
 * the worker's client connections open (DrainCoordinator):
 *
 * - it sends this worker's WebSockets a close frame with 1012 ("service restart"), so clients
 *   reconnect;
 * - it dispatches WorkerDrainStartedEvent, where other contexts save their state.
 *
 * Requests that still arrive on kept-alive connections are served, with Connection: close
 * (DrainingResponseListener), and /ready answers 503. Once the worker handles no request and the
 * clients of its WebSockets have answered the close, it reports itself drained in the DrainStateTable and clears its tick.
 * Half a second before the window ends (STREAM_CUT_LEAD_MILLISECONDS), it cuts the streams it is
 * still sending (WorkerStreamAborterInterface), and repeats that on every tick until it is idle.
 * The lead leaves the cut streams time to stop, so the worker reports drained before the master
 * resets the connections at the end of the window.
 *
 * A reload never sets the flag, so a reloading worker runs none of this. The tick is the only
 * timer here; DrainWorkerExitSubscriber clears it if the worker stops before it reported.
 *
 * "Handling no request" is Swoole's worker_concurrency: the requests this worker is running,
 * including streamed responses. Timer ticks, WebSocket messages already handled and idle
 * kept-alive connections do not count.
 */
final class WorkerDrain implements EventSubscriberInterface
{
    public const int WEBSOCKET_CLOSE_CODE = 1012;
    public const string WEBSOCKET_CLOSE_REASON = 'service restart';
    /** How long before the window ends a worker cuts its streams. */
    public const int STREAM_CUT_LEAD_MILLISECONDS = 500;
    private const int TICK_MILLISECONDS = 100;

    private ?int $timerId = null;
    private bool $stepsRun = false;
    private bool $reported = false;
    /** @var list<int> WebSockets this worker closed whose connection Swoole has not yet closed */
    private array $closingWebSockets = [];
    /** @var \Closure(): int */
    private readonly \Closure $monotonicNs;

    /**
     * The container passes the CoWrapper and the pusher; they are optional only for tests.
     *
     * @param (\Closure(): int)|null $monotonicNs hrtime(true) unless a test replaces it
     */
    public function __construct(
        private readonly DrainStateTable $state,
        private readonly DrainBudget $budget,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?WebSocketPusher $webSockets = null,
        private readonly ?WorkerStreamAborterInterface $streams = null,
        private readonly ?CoWrapper $coWrapper = null,
        ?\Closure $monotonicNs = null,
    ) {
        $this->monotonicNs = $monotonicNs ?? static fn (): int => hrtime(true);
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::NAME => 'onWorkerStarted'];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $server = $event->getServer();
        if ($server->taskworker || $this->timerId !== null) {
            return;
        }

        $timerId = Timer::tick(self::TICK_MILLISECONDS, function () use ($server): void {
            $this->poll($server);
        });
        // Native Swoole may return false; usable timer ids are positive.
        if ($timerId < 1) {
            error_log(sprintf('[WorkerDrain] Worker %d could not start its drain timer; it will not report drained', $event->getWorkerId()));

            return;
        }
        $this->timerId = $timerId;
    }

    /** One look at the drain flag; public for the timer callback and tests. */
    public function poll(Server $server): void
    {
        if ($this->reported || !$this->state->isDraining()) {
            return;
        }
        // The steps log and their listeners may use pooled services.
        $this->coWrapper?->defer();
        $workerId = (int) $server->worker_id;

        if (!$this->stepsRun) {
            $this->stepsRun = true;
            $this->runSteps($server, $workerId);
        }

        if (!$this->isIdle($server)) {
            $streams = $this->streams;
            if ($streams !== null && ($this->monotonicNs)() >= $this->streamCutAtNs()) {
                $this->attempt('cut its streams', $workerId, static fn () => $streams->abortAll());
            }

            return;
        }

        $this->reported = true;
        $this->stop();
        $this->attempt('report itself drained', $workerId, fn () => $this->state->markWorkerDrained($workerId));
    }

    /** Ends the tick. Safe to call more than once. */
    public function stop(): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
    }

    private function runSteps(Server $server, int $workerId): void
    {
        $this->attempt('close its WebSockets', $workerId, function () use ($workerId): void {
            $this->closingWebSockets = $this->webSockets?->closeWorkerConnections(
                $workerId,
                self::WEBSOCKET_CLOSE_CODE,
                self::WEBSOCKET_CLOSE_REASON,
            ) ?? [];
        });
        $this->attempt('run its drain listeners', $workerId, function () use ($server, $workerId): void {
            $this->dispatcher->dispatch(new WorkerDrainStartedEvent($server, $workerId));
        });
    }

    private function isIdle(Server $server): bool
    {
        // A WebSocket closes once its client has answered the close frame. The master resets
        // every connection still open when the window ends, which would cut that handshake short.
        $this->closingWebSockets = array_values(array_filter(
            $this->closingWebSockets,
            static fn (int $fd): bool => $server->exist($fd),
        ));
        if ($this->closingWebSockets !== []) {
            return false;
        }

        $stats = $server->stats();

        return (int) ($stats['worker_concurrency'] ?? 0) === 0;
    }

    private function streamCutAtNs(): int
    {
        $startedAtNs = $this->state->startedAtNs() ?? ($this->monotonicNs)();

        return $startedAtNs + $this->budget->windowSeconds * 1_000_000_000 - self::STREAM_CUT_LEAD_MILLISECONDS * 1_000_000;
    }

    /** A failed step is logged and the drain goes on: the master's window ends on its own. */
    private function attempt(string $step, int $workerId, \Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $error) {
            $this->logger->error(sprintf('Web server worker %d could not %s during the drain.', $workerId, $step), ['exception' => $error]);
        }
    }
}
