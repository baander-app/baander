<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Web;

use Baander\Tooling\Development\Docker;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\ProcessResult;
use Throwable;

final class ShutdownWorkflow
{
    private const int DRAIN_SECONDS = 10;
    private const int STOP_TIMEOUT = 15;
    private const int WINDOW_SECONDS = 3;
    private const string LISTENER = 'drain-listener@baander.app';
    private Runtime $runtime;
    private Docker $docker;
    private string $id;
    private string $image;
    private string $appDirectory;
    private string $temporary;
    private int $logOffset = 0;
    private float $memoryPeak = 0;
    private ?float $memoryLimit = null;
    /**
     * @var list<string>
     */

    private array $appEnvironment;
    public function __construct(string $root)
    {
        $this->runtime = new Runtime($root, 900);
        $this->runtime->environment['LC_ALL'] = 'C';
        $this->docker = $this->runtime->docker();
        $this->id = 'baander-web-shutdown-' . time() . '-' . getmypid();
        $this->image = $this->runtime->environment['BAANDER_TEST_IMAGE'] ?? '' ?: 'martinjuul/baander-app:latest';
        $this->appDirectory = $this->baked() ? '/var/www/html' : '/tmp/baander-web-shutdown';
        $this->temporary = sys_get_temp_dir() . '/' . $this->id . '-' . bin2hex(random_bytes(4));
        Runtime::require(mkdir($this->temporary, 0700), 'Cannot create private shutdown workflow directory.');
        $this->appEnvironment = [
            '-e',
            'APP_ENV=prod',
            '-e',
            'APP_DEBUG=0',
            '-e',
            'XDEBUG_MODE=off',
            '-e',
            'OTEL_ENABLED=false',
            '-e',
            'APP_SECRET=web-shutdown-test-only-secret',
            '-e',
            'STREAM_URL_HMAC_SECRET=web-shutdown-test-only-hmac',
            '-e',
            'APP_URL=https://baander.app',
            '-e',
            'DEFAULT_URI=https://baander.app',
            '-e',
            'APP_DOMAIN=http://127.0.0.1:9501',
            '-e',
            'MAILER_DSN=null://null',
            '-e',
            'MAIL_FROM_ADDRESS=noreply@baander.app',
            '-e',
            'MEDIA_STORAGE_PATH=' . $this->appDirectory . '/storage/media',
            '-e',
            'CONVERT_STORAGE_PATH=' . $this->appDirectory . '/storage/conversions',
            '-e',
            'REDIS_PASSWORD=test-only',
            '-e',
            'REDIS_URL=redis://default:test-only@redis:6379',
            '-e',
            'MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages',
            '-e',
            'DATABASE_URL=postgresql://baander:test-only@postgres:5432/web_shutdown_test?serverVersion=18&charset=utf8',
            '-e',
            'WEB_DRAIN_SECONDS=10',
        ];
    }
    private function baked(): bool
    {
        return ($this->runtime->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1';
    }
    /**
     * @param list<string> $arguments
     */

    private function docker(array $arguments, bool $check = true, float $timeout = 120): ProcessResult
    {
        return $this->docker->run($arguments, check: $check, timeout: $timeout);
    }
    /**
     * @param non-empty-list<string> $arguments
     */

    private function exec(string $container, array $arguments, bool $check = true, ?string $input = null, float $timeout = 120): ProcessResult
    {
        return $this->docker->run(
            ['exec', '-i', '-w', $this->appDirectory, $this->id . '-' . $container, ...$arguments],
            check: $check,
            input: $input,
            timeout: $timeout,
        );
    }
    private function logs(string $container): string
    {
        return $this->runtime->run([
            'bash',
            '-c',
            'exec "$@" 2>&1',
            'shutdown-logs',
            ...$this->docker->commandPrefix(),
            'logs',
            $this->id . '-' . $container,
        ])->stdout;
    }
    private function runLog(): string
    {
        $lines = explode("\n", rtrim($this->logs('web'), "\n"));
        return implode("\n", array_slice($lines, $this->logOffset));
    }
    /**
     * @param list<string> $paths
     */

    private function stage(array $paths, string $container, string $destination): void
    {
        $archive = $this->temporary . '/checkout.tar';
        try {
            $this->runtime->run(['tar', '-cf', $archive, ...$paths]);
            if ($container === 'probe') {
                $this->copyArchive($archive, $this->id . '-probe:' . $destination);
                return;
            }
            Runtime::require(chmod($archive, 0644), 'Cannot make transferred archive readable by the container user.');
            $this->docker(['cp', $archive, $this->id . '-' . $container . ':/tmp/shutdown-checkout.tar']);
            $this->docker(['exec', '--user', '0', $this->id . '-' . $container, 'mkdir', '-p', $destination]);
            $this->docker([
                'exec',
                '-w',
                $destination,
                $this->id . '-' . $container,
                'tar',
                '-xf',
                '/tmp/shutdown-checkout.tar',
            ]);
            $this->docker(['exec', $this->id . '-' . $container, 'rm', '/tmp/shutdown-checkout.tar']);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }
    private function copyClientToWeb(): void
    {
        $archive = $this->temporary . '/web.tar';
        $errors = $this->temporary . '/web-tar.stderr';
        try {
            $this->runtime->run(
                [
                    ...$this->docker->commandPrefix(),
                    'exec',
                    '-w',
                    $this->appDirectory,
                    $this->id . '-client',
                    'tar',
                    '-cf',
                    '-',
                    $this->baked() ? 'storage' : '.',
                ],
                logPath: $archive,
                stderrLogPath: $errors,
            );
            $this->copyArchive($archive, $this->id . '-web:' . $this->appDirectory, true);
        } finally {
            foreach ([$archive, $errors] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
    private function copyArchive(string $archive, string $destination, bool $preserveOwner = false): void
    {
        $arguments = ['cp'];
        if ($preserveOwner) {
            $arguments[] = '-a';
        }
        array_push($arguments, '-', $destination);
        $this->runtime->run([
            'bash',
            '-o',
            'pipefail',
            '-c',
            'cat -- "$1" | "${@:2}"',
            'shutdown-copy',
            $archive,
            ...$this->docker->commandPrefix(),
            ...$arguments,
        ]);
    }
    private function file(string $container, string $path): string
    {
        $destination = $this->temporary . '/copied-' . bin2hex(random_bytes(4));
        try {
            $this->docker(['cp', $this->id . '-' . $container . ':' . $path, $destination]);
            $body = file_get_contents($destination);
            Runtime::require($body !== false, 'Cannot read copied shutdown log.');
            return $body;
        } finally {
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }
    private function setup(): void
    {
        $this->docker(['network', 'create', '--internal', $this->id]);
        $this->docker([
            'run',
            '-d',
            '--name',
            $this->id . '-redis',
            '--network',
            $this->id,
            '--network-alias',
            'redis',
            '-e',
            'REDIS_ARGS=--requirepass test-only',
            'redis/redis-stack-server:edge',
        ]);
        $this->docker([
            'run',
            '-d',
            '--name',
            $this->id . '-postgres',
            '--network',
            $this->id,
            '--network-alias',
            'postgres',
            '-e',
            'POSTGRES_USER=baander',
            '-e',
            'POSTGRES_PASSWORD=test-only',
            '-e',
            'POSTGRES_DB=web_shutdown_test',
            $this->runtime->environment['BAANDER_TEST_POSTGRES_IMAGE'] ?? '' ?: 'baander-database:latest',
        ]);
        $this->docker([
            'run',
            '-d',
            '--name',
            $this->id . '-client',
            '--privileged',
            '--network',
            $this->id,
            '--entrypoint',
            'sleep',
            ...$this->appEnvironment,
            $this->image,
            'infinity',
        ]);
        if (!$this->baked()) {
            $this->exec('client', ['mkdir', '-p', $this->appDirectory]);
            $this->stage(
                [
                    'vendor',
                    'src',
                    'tests/Fixtures/WebShutdown',
                    'tests/Fixtures/WebRuntime',
                    'tests/Fixtures/Auth',
                    'tests/Fixtures/web-runtime.php',
                    'config',
                    'packages',
                    'migrations',
                    'bin',
                    'docker/general',
                    'templates',
                    'public',
                    '.env',
                    'composer.json',
                    'composer.lock',
                    'translations',
                ],
                'client',
                $this->appDirectory,
            );
        }
        $this->exec('client', ['mkdir', '-p', 'storage/media', 'storage/conversions']);
        $this->exec(
            'client',
            [
                'sh',
                '-c',
                'test -f config/secrets/oauth/private.key || '
                    . '(umask 077 && mkdir -p config/secrets/oauth && '
                    . 'openssl genrsa -out config/secrets/oauth/private.key 2048 2>/dev/null && '
                    . 'openssl rsa -in config/secrets/oauth/private.key -pubout '
                    . '-out config/secrets/oauth/public.key 2>/dev/null)',
            ],
        );
    }
    private function probe(): void
    {
        $this->docker([
            'create',
            '--name',
            $this->id . '-probe',
            '--network',
            $this->id,
            '--network-alias',
            'probe',
            '--entrypoint',
            'php',
            $this->image,
            '/tmp/tests/Fixtures/WebShutdown/shutdown-order-probe.php',
        ]);
        $this->stage(['tests/Fixtures/WebShutdown'], 'probe', '/tmp');
        $this->docker(['start', $this->id . '-probe']);
        $this->runtime->await(
            fn(): bool => str_contains($this->logs('probe'), ' master_start '),
            5,
            0.1,
            'The probe server did not start.',
        );
        $this->docker([
            'exec',
            '-d',
            '-w',
            $this->appDirectory,
            $this->id . '-client',
            'sh',
            '-c',
            'exec php tests/Fixtures/WebShutdown/shutdown-order-client.php probe > /tmp/shutdown-order-client.log 2>&1',
        ]);
        $this->runtime->await(
            function (): bool {
                return $this->exec('client', ['grep', '-q', ' ready ', '/tmp/shutdown-order-client.log'], false)->exitCode === 0
                    && str_contains($this->logs('probe'), ' hold_received ')
                    && str_contains($this->logs('probe'), ' ws_open ');
            },
            5,
            0.1,
            'The probe client did not open its connections.',
        );
        $first = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-probe']);
        $this->runtime->pause(1);
        $second = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-probe']);
        $exit = $this->docker(['wait', $this->id . '-probe'], timeout: 30)->stdout
            |> trim(...);
        Runtime::require($exit === '0', 'The probe server exited ' . $exit);
        $this->runtime->await(
            fn(): bool => $this->exec('client', ['grep', '-q', ' client_done ', '/tmp/shutdown-order-client.log'], false)->exitCode === 0,
            5,
            0.1,
            'The probe client did not finish.',
        );
        $timeline = "drill {$first} sigterm\ndrill {$second} sigterm\n" . $this->logs('probe') . "\n" . $this->exec('client', ['cat', '/tmp/shutdown-order-client.log'])->stdout;
        echo "Shutdown order of the probe server (seconds after the first SIGTERM):\n";
        echo ShutdownAssertions::timeline($timeline, $first);
        ShutdownAssertions::probe($timeline);
        echo "PASS: Swoole kept the server open during the master timer, then closed connections before stopping workers.\n";
    }
    private function prepareWeb(): string
    {
        $this->runtime->await(
            function (): bool {
                return $this->docker(
                    [
                        'exec',
                        $this->id . '-postgres',
                        'pg_isready',
                        '-h',
                        '127.0.0.1',
                        '-U',
                        'baander',
                        '-d',
                        'web_shutdown_test',
                    ],
                    false,
                )->exitCode === 0 && trim($this->docker(
                    ['exec', '-e', 'REDISCLI_AUTH=test-only', $this->id . '-redis', 'redis-cli', 'ping'],
                    false,
                )->stdout) === 'PONG';
            },
            30,
            1,
            'PostgreSQL or Redis did not become ready.',
        );
        $this->docker([
            'exec',
            $this->id . '-postgres',
            'pg_isready',
            '-h',
            '127.0.0.1',
            '-U',
            'baander',
            '-d',
            'web_shutdown_test',
        ]);
        $this->exec('client', ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        $this->exec(
            'client',
            [
                'php',
                'bin/console',
                'app:user:create',
                '--no-interaction',
                '--password',
                '--role',
                'admin',
                '--force',
                self::LISTENER,
                'Drain Listener',
            ],
            input: "Web-shutdown-test-only-1\n",
        );
        $this->exec(
            'client',
            ['php', 'bin/console', 'app:settings:set', 'transcode.enabled', 'true', '--no-interaction'],
        );
        $this->exec('client', ['php', 'tests/Fixtures/web-runtime.php', 'prepare']);
        $prepared = $this->exec('client', ['cat', '/tmp/baander-web-runtime.json'])->stdout
            |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $this->docker([
            'create',
            '--name',
            $this->id . '-web',
            '--privileged',
            '--network',
            $this->id,
            '-w',
            $this->appDirectory,
            ...$this->appEnvironment,
            $this->image,
            $this->appDirectory . '/docker/general/start-web.sh',
        ]);
        $this->copyClientToWeb();
        return (string) $prepared['track'];
    }
    private function assertDeliveryOnly(string $container): void
    {
        ShutdownAssertions::deliveryOnly(
            $this->exec($container, ['curl', '-fsS', 'http://127.0.0.1:9200/api/server'])->stdout,
            $this->exec($container, ['ps', '-eo', 'stat=,args='])->stdout,
        );
    }
    private function startWeb(): void
    {
        $log = rtrim($this->logs('web'), "\n");
        $this->logOffset = $log === '' ? 0 : count(explode("\n", $log));
        $this->docker(['start', $this->id . '-web']);
        $this->runtime->await(
            fn(): bool => $this->exec('web', ['curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9501/live'], false)->exitCode === 0,
            90,
            1,
            'app:serve did not answer /live.',
        );
        $this->assertDeliveryOnly('web');
        $command = $this->exec('web', ['cat', '/proc/1/cmdline'])->stdout;
        Runtime::require(
            str_contains(str_replace("\x00", ' ', $command), 'bin/console app:serve'),
            'app:serve is not PID 1.',
        );
    }
    /**
     * @return array<string,mixed>
     */

    private function serverStatus(): array
    {
        return $this->exec('web', ['curl', '-fsS', 'http://127.0.0.1:9200/api/server'])->stdout
            |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
    /**
     * @return list<string>
     */

    private function workerPids(): array
    {
        $manager = (string) $this->serverStatus()['server']['processes']['manager']['pid'];
        return $this->exec('web', ['pgrep', '-P', $manager])->stdout
            |> trim(...)
            |> (static fn(string $text): array => preg_split('/\s+/', $text) ?: [])
            |> static function (array $pids): array {
                sort($pids);
                return $pids;
            };
    }
    /**
     * @param list<string> $arguments
     */

    private function startClient(string $mode, array $arguments, string $container = 'web'): void
    {
        $command = [
            'php',
            '-d',
            'memory_limit=512M',
            'tests/Fixtures/WebShutdown/drain-client.php',
            $mode,
            ...$arguments,
        ]
            |> (static fn(array $argv): string => implode(' ', array_map(escapeshellarg(...), $argv)));
        $this->docker([
            'exec',
            '-d',
            '-w',
            $this->appDirectory,
            $this->id . '-' . $container,
            'sh',
            '-c',
            'exec ' . $command . ' > ' . escapeshellarg('/tmp/drain-' . $mode . '.log') . ' 2>&1',
        ]);
    }
    private function awaitClient(string $mode, string $event, string $container = 'web'): void
    {
        $this->runtime->await(
            fn(): bool => $this->exec($container, ['grep', '-q', ' ' . $event . ' ', '/tmp/drain-' . $mode . '.log'], false)->exitCode === 0,
            60,
            0.1,
            "The {$mode} client did not record {$event}.",
        );
    }
    private function awaitExit(): void
    {
        $this->runtime->await(fn(): bool => $this->state('Running') === 'false', 20, 0.1, 'app:serve did not exit.');
    }
    private function state(string $field): string
    {
        return $this->docker(['inspect', '--format', '{{.State.' . $field . '}}', $this->id . '-web'])->stdout
            |> trim(...);
    }
    private function assertStopped(string $scenario, float $started, int $code = 0, float $limit = 10): void
    {
        $elapsed = round(microtime(true) - $started, 1);
        Runtime::require(
            $this->state('Running') === 'false',
            "{$scenario}: app:serve was still running after {$elapsed} seconds.",
        );
        Runtime::require($this->state('OOMKilled') === 'false', "{$scenario}: app:serve was OOM-killed.");
        $actual = $this->state('ExitCode');
        Runtime::require(
            $actual === (string) $code && $elapsed < $limit,
            "{$scenario}: app:serve exited {$actual} after {$elapsed} seconds, expected {$code} before {$limit} seconds.",
        );
        ShutdownAssertions::drainCount($this->runLog(), $code);
        echo "PASS: {$scenario}: app:serve exited {$actual} after {$elapsed} seconds.\n";
    }
    private function assertDrained(string $scenario): void
    {
        $window = ShutdownAssertions::drainedWindow($this->runLog(), true);
        echo "PASS: {$scenario}: every worker reported drained; the window closed after {$window} seconds.\n";
    }
    private function sampleMemory(string $container = 'web'): void
    {
        $until = microtime(true) + 5.5;
        $last = '';
        do {
            $last = $this->exec($container, ['curl', '-sS', '--max-time', '2', 'http://127.0.0.1:9501/health'], false)->stdout;
            $memory = ShutdownAssertions::memory($last);
            if ($memory !== null) {
                $this->memoryLimit = $memory['limit'];
                $this->memoryPeak = max($this->memoryPeak, $memory['peak']);
            }
            $this->runtime->pause(0.5);
        } while (microtime(true) < $until);
        Runtime::require(
            $this->memoryLimit !== null,
            '/health reported no worker memory against memory_limit: ' . $last,
        );
    }
    private function reloadAndSignals(): void
    {
        $this->startWeb();
        $this->startClient('ws', [self::LISTENER]);
        $this->awaitClient('ws', 'ws_pong');
        $before = $this->workerPids();
        $started = $this->state('StartedAt');
        $this->exec(
            'web',
            ['curl', '-fsS', '-X', 'PATCH', '-o', '/dev/null', 'http://127.0.0.1:9200/api/server'],
        );
        $this->runtime->await(
            fn(): bool => array_intersect($before, $this->workerPids()) === [],
            10,
            0.1,
            'Reload did not replace every worker.',
        );
        $reloaded = microtime(true);
        $this->runtime->pause(1);
        Runtime::require(
            $this->state('StartedAt') === $started && $this->state('Running') === 'true',
            'Reload restarted the server.',
        );
        $this->assertDeliveryOnly('web');
        $ws = $this->exec('web', ['cat', '/tmp/drain-ws.log'])->stdout;
        Runtime::require(
            preg_match('/ ws_(closed|close_frame) /', $ws) !== 1,
            'Reload closed the WebSocket: ' . $ws,
        );
        Runtime::require(
            array_any(
                ShutdownAssertions::selected($ws, 'client', 'ws_pong'),
                static fn(array $event): bool => $event['time'] > $reloaded,
            ),
            'WebSocket answered no ping after reload.',
        );
        Runtime::require(!str_contains($this->runLog(), 'Web server drain started'), 'Reload started a drain.');
        echo "PASS: PATCH /api/server reloaded every worker and kept the delivery server and the WebSocket, with no drain.\n";
        $started = microtime(true);
        $this->docker(['stop', '-t', (string) self::STOP_TIMEOUT, $this->id . '-web']);
        $this->assertStopped('docker stop (SIGTERM)', $started);
        $this->assertDrained('docker stop (SIGTERM) with a WebSocket open');
        ShutdownAssertions::websocket($this->file('web', '/tmp/drain-ws.log'));
        echo "PASS: the open WebSocket received close code 1012 before its connection ended.\n";
        foreach ([
            'SIGINT',
            'DELETE /api/server',
            'swoole:server:stop',
            'SIGTERM, then SIGINT a second later',
        ] as $scenario) {
            $this->startWeb();
            $started = microtime(true);
            if ($scenario === 'SIGINT') {
                $this->docker(['kill', '--signal', 'INT', $this->id . '-web']);
            } elseif ($scenario === 'DELETE /api/server') {
                $this->exec(
                    'web',
                    ['curl', '-fsS', '-X', 'DELETE', '-o', '/dev/null', 'http://127.0.0.1:9200/api/server'],
                );
            } elseif ($scenario === 'swoole:server:stop') {
                $result = $this->exec(
                    'web',
                    ['php', 'bin/console', 'swoole:server:stop', '--no-interaction'],
                    timeout: self::STOP_TIMEOUT,
                );
                Runtime::require(
                    str_contains($result->stdout . $result->stderr, 'stop requested'),
                    'swoole:server:stop did not report the stop request.',
                );
                echo "PASS: swoole:server:stop exited 0 once the stop was requested.\n";
            } else {
                $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
                $this->runtime->pause(1);
                $this->docker(['kill', '--signal', 'INT', $this->id . '-web'], false);
            }
            $this->awaitExit();
            $this->assertStopped($scenario, $started, limit: $scenario === 'SIGINT' ? 4 : 10);
            if ($scenario !== 'SIGTERM, then SIGINT a second later') {
                $this->assertDrained($scenario);
            }
        }
    }
    private function stalledAndWatchdog(string $track): void
    {
        $this->startWeb();
        $this->startClient('stalled-stream', [self::LISTENER, $track]);
        $this->awaitClient('stalled-stream', 'stalled');
        foreach (['keepalive-ready' => 'ready', 'keepalive-live' => 'live'] as $mode => $event) {
            $this->startClient($mode, [self::LISTENER]);
            $this->awaitClient($mode, $event);
        }
        $this->sampleMemory();
        $started = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
        $this->awaitExit();
        $this->assertStopped('a client that stopped reading a progressive stream', $started);
        ShutdownAssertions::drainedWindow($this->runLog());
        foreach (['keepalive-ready' => false, 'keepalive-live' => true] as $mode => $live) {
            ShutdownAssertions::keepalive($this->file('web', '/tmp/drain-' . $mode . '.log'), $live);
        }
        echo "PASS: /ready on a connection kept alive from before the stop answered 503 with Connection: close.\n";
        echo "PASS: /live on a connection kept alive from before the stop answered 200 with draining=true.\n";
        $this->startWeb();
        $worker = (string) $this->serverStatus()['server']['processes']['worker']['pid'];
        $this->exec('web', ['kill', '-STOP', $worker]);
        $started = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
        $this->awaitExit();
        $this->assertStopped('an event worker stopped with SIGSTOP (pid ' . $worker . ')', $started);
        $this->startWeb();
        $manager = (string) $this->serverStatus()['server']['processes']['manager']['pid'];
        $this->exec('web', ['kill', '-STOP', $manager]);
        $started = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
        $this->awaitExit();
        $this->assertStopped('the manager stopped with SIGSTOP (pid ' . $manager . ')', $started, 1, self::STOP_TIMEOUT);
        Runtime::require(
            round(microtime(true) - $started, 1) >= self::DRAIN_SECONDS - 0.5,
            'Watchdog fired before its deadline.',
        );
        $log = $this->runLog();
        Runtime::require(
            preg_match(
                '/^The web server did not stop by its drain deadline: killed processes \[(?:[0-9]+, )*' . preg_quote($manager, '/') . '(?:, [0-9]+)*\]/m',
                $log,
            ) === 1,
            'Watchdog did not report killing the manager.',
        );
        Runtime::require(
            preg_match('/^The web server reached its drain deadline; exiting with status 1\.$/m', $log) === 1,
            'Master did not report its deadline.',
        );
        echo "PASS: the watchdog killed the stopped manager at the deadline.\n";
    }
    private function streamsAndFile(string $track): void
    {
        $this->startWeb();
        $this->exec('web', ['rm', '-f', '/tmp/drain-stop-requested']);
        foreach (['held-stream', 'completing-stream'] as $mode) {
            $this->startClient($mode, [self::LISTENER, $track]);
        }
        foreach (['held-stream', 'completing-stream'] as $mode) {
            $this->awaitClient($mode, 'streaming');
        }
        $this->sampleMemory();
        $this->exec('web', ['touch', '/tmp/drain-stop-requested']);
        $started = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
        $this->awaitExit();
        $this->assertStopped('streams open at the stop', $started);
        $log = $this->runLog();
        ShutdownAssertions::drainedWindow($log);
        Runtime::require(!str_contains($log, 'deadlock'), 'A worker stopped with a coroutine still asleep.');
        $held = $this->file('web', '/tmp/drain-held-stream.log');
        $end = ShutdownAssertions::first($held, 'client', 'ended');
        Runtime::require(
            str_starts_with($end['details'], 'result=18 '),
            'Held stream did not end with curl transfer error 18: ' . $held,
        );
        $cutAfter = round($end['time'] - $started, 1);
        Runtime::require(
            $cutAfter >= self::WINDOW_SECONDS - 0.7 && $cutAfter < self::WINDOW_SECONDS,
            'Held stream was not cut just before the window ended: ' . $cutAfter,
        );
        echo "PASS: a progressive stream held open at the stop was cut after {$cutAfter} seconds with curl's transfer error 18.\n";
        $completing = $this->file('web', '/tmp/drain-completing-stream.log');
        $details = explode(' ', ShutdownAssertions::first($completing, 'client', 'ended')['details']);
        Runtime::require(
            $details[0] === 'result=0' && ($details[2] ?? null) === 'complete=yes',
            'Completing stream did not end with its whole body: ' . $completing,
        );
        echo "PASS: a stream that completed during the drain ended cleanly with its whole body.\n";
        $this->startWeb();
        $this->startClient('completing-file', [self::LISTENER, $track]);
        $this->awaitClient('completing-file', 'streaming');
        $started = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
        $this->awaitExit();
        $this->assertStopped('a file response in flight at the stop', $started);
        $file = $this->file('web', '/tmp/drain-completing-file.log');
        Runtime::require(
            ShutdownAssertions::first($file, 'client', 'streaming')['details'] === 'status=200 length=4194304',
            'File response did not announce Content-Length.',
        );
        $end = ShutdownAssertions::first($file, 'client', 'ended');
        $details = explode(' ', $end['details']);
        Runtime::require(
            $details[0] === 'result=0' && ($details[2] ?? null) === 'complete=yes',
            'File response did not end with its whole body.',
        );
        $done = round($end['time'] - $started, 2);
        $window = ShutdownAssertions::drainedWindow($this->runLog());
        Runtime::require(
            $done >= 0.5 && $window >= $done - 0.3,
            'Drain window closed before the file response finished.',
        );
        echo "PASS: a file response in flight at the stop finished after {$done} seconds with its whole body; the window closed after {$window} seconds.\n";
    }
    private function nonPidOne(string $track): void
    {
        $this->docker([
            'exec',
            '-d',
            '-w',
            $this->appDirectory,
            $this->id . '-client',
            'sh',
            '-c',
            'exec php bin/console app:serve --no-interaction > /tmp/not-pid1-serve.log 2>&1',
        ]);
        $this->runtime->await(
            fn(): bool => $this->exec('client', ['curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9501/live'], false)->exitCode === 0,
            90,
            1,
            'app:serve in the client container did not answer /live.',
        );
        $this->assertDeliveryOnly('client');
        $this->startClient('ranged-file', [self::LISTENER, $track], 'client');
        $this->awaitClient('ranged-file', 'streaming', 'client');
        $this->sampleMemory('client');
        $master = $this->exec('client', ['pgrep', '-o', '-f', '^php bin/console app:serve'])->stdout
            |> trim(...);
        Runtime::require($master !== '1', 'app:serve is PID 1 in the client container.');
        $started = microtime(true);
        $this->exec('client', ['kill', '-TERM', $master]);
        $this->runtime->await(
            fn(): bool => $this->exec('client', ['kill', '-0', $master], false)->exitCode !== 0,
            self::STOP_TIMEOUT,
            0.1,
            'app:serve (not PID 1) did not stop.',
        );
        $elapsed = round(microtime(true) - $started, 1);
        $processes = $this->exec('client', ['ps', '-eo', 'pid=,stat=,args='])->stdout;
        foreach (explode("\n", $processes) as $line) {
            if (preg_match('/^\s*\d+\s+Z/', $line) !== 1) {
                Runtime::require(
                    preg_match(
                        '/ffmpeg|ffprobe|nvidia-smi|cpu-pool-worker|worker-(media|video|audio)[.]php|app:serve/',
                        $line,
                    ) !== 1,
                    'Processes outlived app:serve (not PID 1): ' . $line,
                );
            }
        }
        $this->awaitClient('ranged-file', 'ended', 'client');
        echo "PASS: app:serve (not PID 1) exited {$elapsed} seconds after SIGTERM and left no web server or media executor process.\n";
        $ranged = $this->exec('client', ['cat', '/tmp/drain-ranged-file.log'])->stdout;
        $streaming = ShutdownAssertions::first($ranged, 'client', 'streaming')['details'];
        $ended = ShutdownAssertions::first($ranged, 'client', 'ended')['details'];
        Runtime::require(
            preg_match('/^status=\d+ length=16777216(?: |$)/', $streaming) === 1,
            'Ranged file did not announce Content-Length.',
        );
        Runtime::require(
            preg_match('/^result=(18|56) bytes=([0-9]+)(?: |$)/', $ended, $match) === 1 && (int) $match[2] < 16777216,
            'Ranged response was not cut short of Content-Length: ' . $ranged,
        );
        $serve = $this->exec('client', ['cat', '/tmp/not-pid1-serve.log'])->stdout;
        Runtime::require(
            preg_match(
                '/^Drain window closed after ([0-9.]+) s, the window passed; reset [1-9][0-9]* client connection\(s\)\.$/m',
                $serve,
                $window,
            ) === 1,
            'Ranged response did not keep the drain window open until its deadline.',
        );
        echo "PASS: a ranged file response read slowly at the stop was reset when the window closed after {$window[1]} seconds, after {$match[2]} of 16777216 bytes (curl {$match[1]}).\n";
        Runtime::require(
            $this->memoryLimit !== null && $this->memoryLimit > 0,
            'No worker memory limit was measured.',
        );
        $percent = round(100 * $this->memoryPeak / $this->memoryLimit, 1);
        Runtime::require(
            $percent <= 60,
            "A web worker used {$percent} % of memory_limit during the stream scenarios.",
        );
        echo "PASS: the largest web worker used {$this->memoryPeak} MiB of its {$this->memoryLimit} MiB memory_limit ({$percent} %) during the stream scenarios.\n";
    }
    private function databaseOutage(): void
    {
        $this->docker(['stop', $this->id . '-postgres']);
        $this->startWeb();
        $started = microtime(true);
        $this->docker(['kill', '--signal', 'TERM', $this->id . '-web']);
        $this->awaitExit();
        $this->assertStopped('SIGTERM with PostgreSQL stopped', $started);
        Runtime::require(
            substr_count($this->runLog(), 'Could not clean up orphaned recommendation jobs at server start') === 1,
            'Start-up cleanup did not log its database failure exactly once.',
        );
        echo "PASS: the start-up cleanup logged the unreachable database once.\n";
    }
    private function cleanup(bool $failed): void
    {
        $this->runtime->cleaning = true;
        try {
            if ($failed) {
                foreach (['probe', 'web'] as $container) {
                    try {
                        if ($this->docker(['inspect', $this->id . '-' . $container], false)->exitCode === 0) {
                            $log = $this->docker(['logs', '--tail', '60', $this->id . '-' . $container], false);
                            fwrite(
                                STDERR,
                                "--- {$this->id}-{$container} output (last 60 lines)\n" . $log->stdout . $log->stderr,
                            );
                        }
                    } catch (Throwable $error) {
                        fwrite(STDERR, $error->getMessage() . "\n");
                    }
                }
            }
            foreach ([
                [
                    'rm',
                    '-f',
                    '-v',
                    $this->id . '-web',
                    $this->id . '-probe',
                    $this->id . '-client',
                    $this->id . '-redis',
                    $this->id . '-postgres',
                ],
                ['network', 'rm', $this->id],
            ] as $arguments) {
                try {
                    $this->docker($arguments, false);
                } catch (Throwable $error) {
                    fwrite(STDERR, $error->getMessage() . "\n");
                }
            }
        } finally {
            $this->runtime->close();
            foreach (glob($this->temporary . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->temporary);
        }
    }
    public function run(): void
    {
        $failed = true;
        try {
            $this->setup();
            $this->probe();
            $track = $this->prepareWeb();
            $this->reloadAndSignals();
            $this->stalledAndWatchdog($track);
            $this->streamsAndFile($track);
            $this->nonPidOne($track);
            $this->databaseOutage();
            $failed = false;
        } finally {
            $this->cleanup($failed);
        }
    }
}
