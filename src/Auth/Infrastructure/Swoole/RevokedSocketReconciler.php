<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Swoole;

use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Application\Port\WorkerTimerInterface;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use Psr\Log\LoggerInterface;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Closes every WebSocket connection whose access token is no longer live, whatever revoked it.
 *
 * A logout, a revoke or a reuse verdict closes the chain's sockets at once from the request
 * that revoked it. This tick is the backstop for everything else: a close that request could
 * not finish, a user disabled from a console process in another container, a password change,
 * a client revocation, or an access token that simply expired while its socket stayed open.
 * Each run lists the distinct access token ids of every registered connection, asks the
 * database which of them are still stored, unrevoked and unexpired on its clock, and closes
 * the connections of the rest in-process through the server control channel. A connection
 * whose token died is closed within one interval.
 *
 * The timer runs in HTTP worker 0 only, the first time one interval after the worker starts.
 * Task workers and the other HTTP workers start nothing. The timer is cleared when the worker
 * stops, so an old and a new worker 0 never tick together across a reload for longer than the
 * reload itself; while both run, closing a socket that is already gone counts as done.
 *
 * Each tick runs in a coroutine the swoole bundle does not manage and uses the database
 * connection, so it releases its pooled services first (CoWrapper::defer()). A tick that
 * finds this worker's previous tick still running logs a warning and returns. Once the
 * server drains, ticks do nothing, and the drain clears the timer when the worker exits
 * (DrainWorkerExitSubscriber calls stop() through WorkerTimerInterface).
 *
 * A close that reports fewer connections than the registry listed, or that the control
 * channel could not carry out, is logged at error level and left to the next tick; no run
 * throws past the tick. A log line counts the dead tokens and never carries a token id.
 */
final class RevokedSocketReconciler implements EventSubscriberInterface, WorkerTimerInterface
{
    private ?int $timerId = null;
    private bool $ticking = false;

    /** The container passes the CoWrapper and the drain state; they are optional only for tests. */
    public function __construct(
        private readonly WebSocketConnectionRegistry $registry,
        private readonly AccessTokenRepositoryInterface $accessTokens,
        private readonly LiveConnectionsPortInterface $liveConnections,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
        private readonly ?CoWrapper $coWrapper = null,
        private readonly ?ServerDrainStateInterface $drain = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::NAME => 'onWorkerStarted',
            WorkerStoppedEvent::NAME => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($event->getServer()->taskworker || $event->getWorkerId() !== 0 || $this->timerId !== null) {
            return;
        }

        $timerId = Timer::tick($this->intervalSeconds * 1000, function (): void {
            $this->tick();
        });
        // Native Swoole may return false; usable timer ids are positive.
        if ($timerId < 1) {
            error_log('[RevokedSocketReconciler] Worker 0 could not start the socket reconciliation timer');

            return;
        }
        $this->timerId = $timerId;
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->stop();
    }

    public function stop(): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
    }

    /** One reconciliation run; public for the timer callback and tests. */
    public function tick(): void
    {
        $this->coWrapper?->defer();
        if ($this->drain?->isDraining() === true) {
            return;
        }
        if ($this->ticking) {
            $this->logger->warning('Socket reconciliation tick skipped: the previous tick is still running.');

            return;
        }

        $this->ticking = true;
        try {
            $this->reconcile();
        } catch (\Throwable $e) {
            $this->logger->error('Socket reconciliation failed; the next tick runs it again.', ['exception' => $e]);
        } finally {
            $this->ticking = false;
        }
    }

    private function reconcile(): void
    {
        $tokenIds = $this->registry->getConnectedAccessTokenIds();
        if ($tokenIds === []) {
            return;
        }

        $dead = array_values(array_diff($tokenIds, $this->accessTokens->findLiveTokenIds($tokenIds)));
        if ($dead === []) {
            return;
        }

        // Counted before the close: a close removes the registry rows asynchronously, on the
        // owning worker's close event.
        $expected = count($this->registry->getConnectionFdsForAccessTokens($dead));
        try {
            $closed = $this->liveConnections->closeForAccessTokens($dead);
        } catch (ServerControlException $e) {
            $this->logger->error(
                'The web server did not close the WebSocket connections of {count} dead access tokens; the next tick retries.',
                ['count' => count($dead), 'exception' => $e],
            );

            return;
        }

        if ($closed < $expected) {
            $this->logger->error(
                'The web server closed {closed} of the {expected} WebSocket connections of {count} dead access tokens; the next tick retries the rest.',
                ['closed' => $closed, 'expected' => $expected, 'count' => count($dead)],
            );
        }
    }
}
