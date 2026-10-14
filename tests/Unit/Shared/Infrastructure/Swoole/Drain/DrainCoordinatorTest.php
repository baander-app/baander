<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Each scenario runs a real process-mode Swoole server in its own PHP process, with a drain
 * budget of 8 seconds (window 2 s, worker stop wait 1 s). The server stops itself with SIGTERM.
 */
final class DrainCoordinatorTest extends TestCase
{
    private const string SCENARIO = <<<'PHP'
        require $argv[1];
        use App\Shared\Infrastructure\Swoole\Drain\DrainBudget;
        use App\Shared\Infrastructure\Swoole\Drain\DrainCoordinator;
        use App\Shared\Infrastructure\Swoole\Drain\DrainShutdownHandler;
        use App\Shared\Infrastructure\Swoole\Drain\DrainStateTable;
        use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
        use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolShutdownHandler;
        use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
        use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
        use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
        use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\EventDispatcher\EventDispatchingServerStartHandler;
        use SwooleBundle\SwooleBundle\Server\Configurator\WithServerBeforeShutdownHandler;
        final class DrainProbeWorker implements ProcessPoolWorkerInterface {
            public function supportedTypes(): array { return ['probe']; }
            public function handle(string $payload): string { return 'completed'; }
        }
        function record(string $event, array $details = []): void {
            fwrite(STDOUT, json_encode(['event' => $event, 'at' => hrtime(true) / 1e9] + $details) . "\n");
        }
        function trackPid(int $pid): void {
            $stat = file_get_contents('/proc/' . $pid . '/stat');
            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            file_put_contents($GLOBALS['argv'][3] . '/pids', $pid . ' ' . $fields[19] . "\n", FILE_APPEND | LOCK_EX);
        }
        function watchdogs(): int {
            $count = 0;
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                $count += (int) in_array('app:server:drain:watch', explode("\0", (string) @file_get_contents($file)), true);
            }
            return $count;
        }
        $mode = $argv[4];
        $pool = new CpuProcessPool([new DrainProbeWorker()], 1, new Psr\Log\NullLogger(), 16, $argv[3]);
        $pool->boot();
        foreach ($pool->workerPids() as $pid) { trackPid($pid); }
        $locator = new Symfony\Component\DependencyInjection\ServiceLocator([CpuProcessPool::class => static fn() => $pool]);
        $state = new DrainStateTable();
        $state->boot();
        $coordinator = new DrainCoordinator(
            new DrainBudget(8),
            $state,
            [PHP_BINARY, $argv[2], $argv[1], 'app:server:drain:watch'],
            $locator,
        );
        $subscriber = new SwooleWorkerEventSubscriber(new SwooleWorkerEventBuffer(), $locator);
        $dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addSubscriber($subscriber);
        $serverStarted = new EventDispatchingServerStartHandler($dispatcher);
        $shutdown = new DrainShutdownHandler(new CpuProcessPoolShutdownHandler($locator), $coordinator);
        // A stream that never ends: the worker writes until the connection's buffers are full,
        // then keeps trying, as a progressive stream follows a growing file.
        $held = new Swoole\Atomic(0);
        // A song file, sent the way the bundle sends a file response: with sendfile, which leaves
        // the worker idle while the reactor thread sends the file.
        $song = $argv[3] . '/song.bin';
        file_put_contents($song, str_repeat('s', 3 * 1048576));
        $server = new Swoole\Http\Server('127.0.0.1', 0, SWOOLE_PROCESS);
        $server->set(['worker_num' => 2, 'reactor_num' => 2, 'buffer_output_size' => 8 * 1024 * 1024, 'log_file' => $argv[3] . '/server.log', 'heartbeat_check_interval' => 1]);
        $coordinator->configure($server);
        record('configured', ['max_wait_time' => $server->setting['max_wait_time']]);
        $server->on('request', static function ($request, $response) use ($held, $mode, $song): void {
            if ($mode === 'sendfile') {
                $response->header('Content-Type', 'audio/flac');
                $response->sendfile($song);
                $held->add();
                return;
            }
            $chunk = str_repeat('x', 65536);
            $response->header('Content-Type', 'audio/mpeg');
            $response->write($chunk);
            $held->add();
            while (true) {
                if (!@$response->write($chunk)) {
                    Swoole\Coroutine::sleep(0.1);
                }
            }
        });
        $server->on('workerStart', static function (Swoole\Server $server, int $workerId) use ($mode, $state): void {
            if ($mode !== 'drained' && $mode !== 'sendfile') { return; }
            // What each event worker's drain steps end with: report drained once the flag is set.
            Swoole\Timer::tick(50, static function (int $timer) use ($state, $workerId): void {
                if ($state->isDraining()) { Swoole\Timer::clear($timer); $state->markWorkerDrained($workerId); }
            });
        });
        if ($mode === 'twice') {
            $server->on('BeforeShutdown', static function (Swoole\Server $server) use ($coordinator): void {
                $timers = static fn (): array => array_values(iterator_to_array(Swoole\Timer::list()));
                $before = $timers();
                $coordinator->handle($server);
                $afterFirst = $timers();
                $coordinator->handle($server);
                record('handled_twice', [
                    'added' => array_values(array_diff($afterFirst, $before)),
                    'removed' => array_values(array_diff($before, $afterFirst)),
                    'unchangedBySecondCall' => $timers() === $afterFirst,
                    'watchdogs' => watchdogs(),
                ]);
            });
        } else {
            (new WithServerBeforeShutdownHandler($coordinator))->configure($server);
        }
        $server->on('shutdown', static function (Swoole\Server $server) use ($shutdown): void {
            record('shutdown_start', ['watchdogs' => watchdogs()]);
            $shutdown->handle($server);
            record('shutdown_end', ['watchdogs' => watchdogs()]);
        });
        $healthTimer = null;
        $server->on('start', static function (Swoole\Server $server) use ($serverStarted, $pool, &$healthTimer, $mode, $held): void {
            trackPid($server->manager_pid);
            $serverStarted->handle($server);
            $healthTimer = (new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool);
            record('health_timer', ['id' => $healthTimer]);
            if ($mode === 'held' || $mode === 'sendfile') {
                Swoole\Timer::tick(20, static function (int $timer) use ($held, $mode): void {
                    // Give the worker a moment to fill the connection's buffers.
                    if ($held->get() > 0) {
                        Swoole\Timer::clear($timer);
                        Swoole\Timer::after($mode === 'held' ? 500 : 100, static function (): void { record('sigterm'); posix_kill(getmypid(), SIGTERM); });
                    }
                });
            } else {
                Swoole\Timer::after(200, static function (): void { posix_kill(getmypid(), SIGTERM); });
            }
        });
        if ($mode === 'held') {
            // A client that reads the start of the stream, then stops reading for 6 seconds, and
            // then records how its connection ended.
            $client = <<<'CLIENT'
                for ($i = 0; $i < 250 && !($socket = @stream_socket_client('tcp://127.0.0.1:' . $argv[1], $errno, $error, 5)); $i++) { usleep(20000); }
                fwrite($socket, "GET /held HTTP/1.1\r\nHost: drain.baander.app\r\n\r\n");
                fread($socket, 1024);
                sleep(6);
                stream_set_timeout($socket, 2);
                while (is_string($data = @fread($socket, 1 << 20)) && $data !== '') {}
                $meta = stream_get_meta_data($socket);
                file_put_contents($argv[2], json_encode(['at' => hrtime(true) / 1e9, 'ended' => $meta['eof'] || $data === false, 'timedOut' => $meta['timed_out']]));
                CLIENT;
            $clientProcess = proc_open([PHP_BINARY, '-r', $client, (string) $server->port, $argv[3] . '/client.json'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $argv[3] . '/client.err', 'w']], $pipes);
            $clientPid = proc_get_status($clientProcess)['pid'];
        } elseif ($mode === 'sendfile') {
            // A client that reads the file at about 2.5 MB/s with a small receive buffer, so the
            // server's side holds what the client has not yet read, and records what it received.
            $client = <<<'CLIENT'
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 65536);
                for ($i = 0; $i < 250 && !@socket_connect($socket, '127.0.0.1', (int) $argv[1]); $i++) { usleep(20000); }
                socket_write($socket, "GET /song HTTP/1.1\r\nHost: drain.baander.app\r\n\r\n");
                $data = '';
                $length = null;
                $body = 0;
                $started = hrtime(true);
                while (true) {
                    $chunk = @socket_read($socket, 65536);
                    if (!is_string($chunk) || $chunk === '') { break; }
                    $data .= $chunk;
                    if ($length === null && ($end = strpos($data, "\r\n\r\n")) !== false) {
                        preg_match('/^Content-Length: (\d+)/mi', substr($data, 0, $end), $match);
                        $length = (int) $match[1];
                        $data = substr($data, $end + 4);
                    }
                    if ($length !== null) {
                        $body += strlen($data);
                        $data = '';
                        if ($body >= $length) { break; }
                    }
                    usleep(max(0, (int) (($body / 2_621_440) * 1e6 - (hrtime(true) - $started) / 1e3)));
                }
                file_put_contents($argv[2], json_encode(['at' => hrtime(true) / 1e9, 'length' => $length, 'received' => $body, 'error' => socket_last_error($socket)]));
                socket_close($socket);
                CLIENT;
            $clientProcess = proc_open([PHP_BINARY, '-r', $client, (string) $server->port, $argv[3] . '/client.json'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $argv[3] . '/client.err', 'w']], $pipes);
            $clientPid = proc_get_status($clientProcess)['pid'];
        }
        // As in app:serve, every coroutine hook is on while the server runs. The stalled stream
        // runs without them: only then does this small server keep the stalled output in its
        // reactor thread, as app:serve does in scripts/test-web-shutdown-container.sh.
        if ($mode !== 'held') {
            Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
        record('start');
        if (!$server->start()) { throw new RuntimeException('The server did not start'); }
        record('stopped', [
            'healthTimerWasSet' => is_int($healthTimer),
            'healthTimerLeft' => is_int($healthTimer) && Swoole\Timer::exists($healthTimer),
        ]);
        if (isset($clientPid)) { pcntl_waitpid($clientPid, $status); }
        PHP;

    public function testTwoDrainCallsStartOneDrainAndEndThePoolHealthCheck(): void
    {
        [$events, $errors] = $this->scenario('twice');

        self::assertSame(1, $events['configured']['max_wait_time']);
        self::assertSame(1, substr_count($errors, 'Web server drain started: deadline 8 s, window 2 s, worker stop wait 1 s.'), $errors);
        self::assertSame(1, substr_count($errors, 'The web server is already draining; this stop request changes nothing.'), $errors);
        // One window tick and one watchdog; the pool's health check is gone.
        self::assertCount(1, $events['handled_twice']['added']);
        self::assertTrue($events['handled_twice']['unchangedBySecondCall']);
        self::assertSame(1, $events['handled_twice']['watchdogs']);
        self::assertSame([$events['health_timer']['id']], $events['handled_twice']['removed']);
        self::assertTrue($events['stopped']['healthTimerWasSet']);
        self::assertFalse($events['stopped']['healthTimerLeft']);
        // No worker reports drained here, so the window lasts its 2 seconds.
        self::assertMatchesRegularExpression('/^Drain window closed after 2\.\d s, the window passed; reset 0 client connection\(s\)\.$/m', $errors);
        self::assertSame(1, $events['shutdown_start']['watchdogs']);
        self::assertSame(0, $events['shutdown_end']['watchdogs'], 'onShutdown left the watchdog running');
        // The watchdog ends on SIGTERM; the master does not wait the second it gives it before SIGKILL.
        self::assertLessThan(0.5, $events['shutdown_end']['at'] - $events['shutdown_start']['at']);
    }

    public function testTheWindowClosesOnceEveryEventWorkerHasReportedDrained(): void
    {
        [$events, $errors] = $this->scenario('drained');

        self::assertSame(1, preg_match('/^Drain window closed after (\d+\.\d) s, every worker drained; reset 0 client connection\(s\)\.$/m', $errors, $match), $errors);
        self::assertLessThan(1.0, (float) $match[1]);
        self::assertLessThan(2.0, $events['stopped']['at'] - $events['start']['at'] - 0.2);
    }

    public function testAtTheWindowEndAStalledStreamIsReset(): void
    {
        [$events, $errors, $client] = $this->scenario('held');

        self::assertMatchesRegularExpression('/^Drain window closed after 2\.\d s, the window passed; reset 1 client connection\(s\)\.$/m', $errors);
        // Without the reset, joining the reactor thread that holds the stream's output never ends.
        self::assertLessThan(5.0, $events['stopped']['at'] - $events['sigterm']['at']);
        self::assertIsArray($client);
        self::assertTrue($client['ended'], 'the server did not close the stalled connection');
        self::assertFalse($client['timedOut']);
    }

    /**
     * A file response leaves its worker idle while the reactor thread sends the file, so the
     * worker reports drained at once; the window stays open until the client has the whole file.
     */
    public function testTheWindowStaysOpenUntilAFileResponseIsSent(): void
    {
        [$events, $errors, $client] = $this->scenario('sendfile');

        self::assertIsArray($client, $errors);
        self::assertSame(3 * 1048576, $client['length']);
        self::assertSame($client['length'], $client['received'], 'the file response was cut: ' . json_encode($client));
        self::assertSame(1, preg_match('/^Drain window closed after (\d+\.\d) s, every worker drained; /m', $errors, $match), $errors);
        // The window closes once the client has the file (the tick and the log round to 0.1 s).
        $clientDoneAfter = $client['at'] - $events['sigterm']['at'];
        self::assertGreaterThan(0.5, $clientDoneAfter, 'the client read the file faster than the test needs');
        self::assertGreaterThanOrEqual($clientDoneAfter - 0.3, (float) $match[1]);
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: string, 2: mixed} */
    private function scenario(string $mode): array
    {
        $root = dirname(__DIR__, 6);
        $directory = sys_get_temp_dir() . '/baander-drain-coordinator-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $process = new Process([
            PHP_BINARY, '-r', self::SCENARIO,
            $root . '/vendor/autoload.php',
            $root . '/tests/Fixtures/WebShutdown/drain-watchdog-console.php',
            $directory,
            $mode,
        ]);
        $process->setTimeout(20);

        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $events = [];
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                // Swoole writes its own reports, such as a worker killed with a sleeping coroutine, to stdout.
                if (!str_starts_with($line, '{"event":')) {
                    continue;
                }
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $events[$event['event']] = $event;
            }
            $client = is_file($directory . '/client.json')
                ? json_decode((string) file_get_contents($directory . '/client.json'), true, 512, JSON_THROW_ON_ERROR)
                : null;

            return [$events, $process->getErrorOutput(), $client];
        } finally {
            $process->stop(1, SIGKILL);
            foreach (is_file($directory . '/pids') ? file($directory . '/pids', FILE_IGNORE_NEW_LINES) : [] as $entry) {
                [$pid, $start] = explode(' ', $entry);
                $stat = @file_get_contents('/proc/' . $pid . '/stat');
                if ($stat === false) { continue; }
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                if ($fields[19] === $start) { \Swoole\Process::kill((int) $pid, SIGKILL); }
            }
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
