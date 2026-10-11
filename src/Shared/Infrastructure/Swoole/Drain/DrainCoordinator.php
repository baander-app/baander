<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Psr\Container\ContainerInterface;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Server\Configurator\Configurator;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerBeforeShutdownHandler;

/**
 * The web server's one drain path, run in the master.
 *
 * SIGTERM, SIGINT (whose listener calls Server::shutdown()), the API server's DELETE route and
 * swoole:server:stop all reach onBeforeShutdown, which Swoole runs once per server. There the
 * coordinator:
 *
 * 1. ends the CPU pool's health check and drops the SIGINT listener. After SIGTERM, Swoole stops
 *    the master's event loop only once no timer is left in it, and it checks for timers before its
 *    own max_wait_time deadline: a timer left behind keeps the server running forever;
 * 2. records the drain start in the DrainStateTable, which every worker reads;
 * 3. starts the watchdog (DrainWatchdog), which kills the manager and the pool at the deadline D;
 * 4. holds the drain window open with a timer of its own. While that timer runs, the master keeps
 *    its reactor threads and so every client connection, and the workers drain. The timer ends
 *    once every event worker has reported drained and no client connection has output left to
 *    send (see anyConnectionStillSending()), or after the window S. It first resets every client
 *    connection still open (see abortSocket()), so that joining the reactor threads cannot wait
 *    on a client that stopped reading.
 *
 * Swoole then closes the connections, stops the workers (each for up to max_wait_time W, which
 * configure() sets) and the manager, and runs onShutdown, where finish() ends the watchdog. If
 * the watchdog had to kill, the master exits with status 1.
 *
 * The master steps touch no pooled service: they write their lines to stderr.
 */
final class DrainCoordinator implements ServerBeforeShutdownHandler, Configurator
{
    private const int WINDOW_TICK_MILLISECONDS = 100;

    private ?DrainWatchdog $watchdog = null;
    private bool $started = false;

    /**
     * @param list<string> $watchdogCommand the watchdog command line, without its options
     * @param resource|null $log where the drain lines go; stderr by default
     */
    public function __construct(
        private readonly DrainBudget $budget,
        private readonly DrainStateTable $state,
        private readonly array $watchdogCommand,
        private readonly ?ContainerInterface $cpuProcessPoolLocator = null,
        private $log = null,
    ) {
    }

    /** Swoole's max_wait_time comes from the drain budget, not from the bundle's settings. */
    public function configure(Server $server): void
    {
        $server->set(['max_wait_time' => $this->budget->maxWaitSeconds]);
    }

    public function handle(Server $server): void
    {
        if ($this->started) {
            $this->say('The web server is already draining; this stop request changes nothing.');

            return;
        }
        $this->started = true;
        $startedAtNs = hrtime(true);

        $pool = $this->cpuProcessPool();
        $pool?->stopHealthCheck();
        Process::signal(SIGINT, null);
        $this->state->begin($startedAtNs);

        $deadlineNs = $startedAtNs + $this->budget->deadlineSeconds * 1_000_000_000;
        $pids = array_values(array_filter([(int) $server->manager_pid, ...($pool?->workerPids() ?? [])], static fn (int $pid): bool => $pid > 1));
        try {
            $this->watchdog = DrainWatchdog::start($this->watchdogCommand, $deadlineNs, $pids, self::foreignProcessGroups($pids));
        } catch (\Throwable $error) {
            // The drain itself does not depend on the watchdog; only the backstop is missing.
            $this->say(sprintf('The drain watchdog did not start, so nothing enforces the drain deadline: %s', $error->getMessage()));
        }

        $this->say(sprintf(
            'Web server drain started: deadline %d s, window %d s, worker stop wait %d s.',
            $this->budget->deadlineSeconds,
            $this->budget->windowSeconds,
            $this->budget->maxWaitSeconds,
        ));
        $this->holdWindow($server, $startedAtNs);
    }

    /**
     * Runs in onShutdown, after the manager has exited: ends the watchdog, and exits the master
     * with status 1 when the drain deadline was reached.
     */
    public function finish(): void
    {
        $watchdog = $this->watchdog;
        $this->watchdog = null;
        if ($watchdog === null || !$watchdog->end()) {
            return;
        }

        $this->say('The web server reached its drain deadline; exiting with status 1.');
        // Replacing this process exits without running destructors that could block (see
        // WorkerSupervisorRunner). As PID 1, its namespace then ends whatever is left.
        pcntl_exec(PHP_BINARY, ['-r', 'exit(1);']);
        throw new \RuntimeException('Cannot exit the web server after its drain deadline.');
    }

    private function holdWindow(Server $server, int $startedAtNs): void
    {
        $windowEndsAtNs = $startedAtNs + $this->budget->windowSeconds * 1_000_000_000;
        $eventWorkers = max(1, (int) ($server->setting['worker_num'] ?? 1));
        Timer::tick(self::WINDOW_TICK_MILLISECONDS, function (int $timerId) use ($server, $startedAtNs, $windowEndsAtNs, $eventWorkers): void {
            $passed = hrtime(true) >= $windowEndsAtNs;
            $drained = $this->state->allWorkersDrained($eventWorkers);
            if (!$passed && (!$drained || self::anyConnectionStillSending($server))) {
                return;
            }
            Timer::clear($timerId);
            $reset = self::resetConnections($server);
            $this->say(sprintf(
                'Drain window closed after %.1f s, %s; reset %d client connection(s).',
                (hrtime(true) - $startedAtNs) / 1e9,
                $passed ? 'the window passed' : 'every worker drained',
                $reset,
            ));
        });
    }

    /**
     * Whether any client connection has output its client has not yet received. A file response
     * (the bundle's sendfile) leaves its worker idle as soon as the worker hands the file to the
     * reactor thread, so worker_concurrency does not count it, and Swoole's send_queued_bytes
     * stays 0 while the reactor thread sends the file. The kernel's send queue of the socket
     * does show it: the reactor thread keeps that queue filled until it has sent the whole file,
     * and the queue empties once the client has acknowledged every byte. Swoole's own output
     * buffer (send_queued_bytes) counts too. The kernel's send queues are read only when a
     * connection needs them.
     */
    private static function anyConnectionStillSending(Server $server): bool
    {
        $queued = null;
        foreach ($server->connections as $fd) {
            $info = $server->getClientInfo((int) $fd);
            if (!is_array($info)) {
                continue;
            }
            if ((int) ($info['send_queued_bytes'] ?? 0) > 0) {
                return true;
            }
            $socketFd = $info['socket_fd'] ?? null;
            $link = is_int($socketFd) ? @readlink('/proc/self/fd/' . $socketFd) : false;
            if (!is_string($link) || preg_match('/^socket:\[(\d+)]$/', $link, $inode) !== 1) {
                continue;
            }
            $queued ??= self::kernelSendQueues();
            if (isset($queued[$inode[1]])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inodes of this network namespace's TCP sockets whose send queue (tx_queue: bytes
     * written but not yet acknowledged) is not empty. Empty where /proc/net is not readable.
     *
     * @return array<string, true>
     */
    private static function kernelSendQueues(): array
    {
        $queued = [];
        self::unhooked(SWOOLE_HOOK_FILE, static function () use (&$queued): void {
            foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $table) {
                $lines = @file($table, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach (array_slice($lines === false ? [] : $lines, 1) as $line) {
                    // sl local_address rem_address st tx_queue:rx_queue tr:tm->when retrnsmt uid timeout inode ...
                    $fields = preg_split('/\s+/', trim($line));
                    if (!is_array($fields) || !isset($fields[9]) || !str_contains($fields[4], ':')) {
                        continue;
                    }
                    if (hexdec(strstr($fields[4], ':', true)) > 0) {
                        $queued[$fields[9]] = true;
                    }
                }
            }
        });

        return $queued;
    }

    private static function resetConnections(Server $server): int
    {
        $connections = [];
        foreach ($server->connections as $fd) {
            $connections[] = (int) $fd;
        }
        $reset = 0;
        foreach ($connections as $fd) {
            $info = $server->getClientInfo($fd);
            if (is_array($info) && is_int($info['socket_fd'] ?? null)) {
                self::abortSocket($info['socket_fd']);
            }
            if ($server->close($fd, true)) {
                ++$reset;
            }
        }

        return $reset;
    }

    /**
     * Server::close() from the master does not close a connection whose reactor thread still
     * holds output for it, such as a stream to a client that stopped reading, and joining that
     * reactor thread then never ends. Shutting the socket down makes the reactor thread's next
     * write fail, so it closes the connection; with SO_LINGER 0 the close sends a reset.
     *
     * The master and its reactor threads share the descriptor; this works on a duplicate of it.
     */
    private static function abortSocket(int $socketFd): void
    {
        $stream = @fopen('php://fd/' . $socketFd, 'r+');
        if ($stream === false) {
            return;
        }
        $socket = @socket_import_stream($stream);
        if ($socket instanceof \Socket) {
            @socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
            @socket_shutdown($socket, 2);
        }
        // The duplicate is closed when $stream, which owns it, goes out of scope.
    }

    /**
     * The process groups of these processes other than the master's own group. Killing the
     * master's group would kill the master, and group 1 or 0 means every process.
     *
     * @param list<int> $pids
     * @return list<int>
     */
    private static function foreignProcessGroups(array $pids): array
    {
        $own = posix_getpgid(0);
        $groups = [];
        foreach ($pids as $pid) {
            $group = posix_getpgid($pid);
            if ($group !== false && $group > 1 && $group !== $own) {
                $groups[$group] = $group;
            }
        }

        return array_values($groups);
    }

    private function cpuProcessPool(): ?CpuProcessPool
    {
        if ($this->cpuProcessPoolLocator === null || !$this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
            return null;
        }

        return $this->cpuProcessPoolLocator->get(CpuProcessPool::class);
    }

    /**
     * Writes a line with Swoole's stdio hook suspended. Hooked, fwrite() in a master coroutine
     * yields; when no timer is left, the master's loop ends before the coroutine resumes, the line
     * is lost and Swoole reports a deadlock.
     */
    private function say(string $line): void
    {
        self::unhooked(SWOOLE_HOOK_STDIO, fn () => fwrite($this->log ?? STDERR, $line . "\n"));
    }

    /**
     * Runs $action with these Swoole coroutine hooks off. A hooked read or write in a master
     * coroutine yields: in the window tick, the next tick could then run beside it.
     */
    private static function unhooked(int $hooks, \Closure $action): void
    {
        $flags = Runtime::getHookFlags();
        $hooked = ($flags & $hooks) !== 0;
        if ($hooked) {
            Runtime::setHookFlags($flags & ~$hooks);
        }
        try {
            $action();
        } finally {
            if ($hooked) {
                Runtime::setHookFlags($flags);
            }
        }
    }
}
