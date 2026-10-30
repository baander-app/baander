<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Http\ProxyQualification;
use Baander\Tooling\Process\{ProcessRunner, ProcessSpec, RunningProcess};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpFixtureTest extends TestCase
{
    private string $directory;
    private ProcessRunner $runner;
    /** @var list<RunningProcess> */
    private array $processes = [];
    /** @var list<string> */
    private array $artifacts = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-http-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->runner = new ProcessRunner();
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $process->cancel();
            $this->runner->wait($process);
        }
        $this->runner->run(new ProcessSpec(argv: ['rm', '-rf', '--', $this->directory, ...$this->artifacts], environment: getenv(), timeout: 5));
    }

    /** @param non-empty-list<string> $arguments */
    private function start(array $arguments): int
    {
        $ready = $this->directory . '/ready';
        $this->processes[] = $this->runner->start(new ProcessSpec(
            argv: [...$arguments, $ready, '--port', '0'],
            environment: getenv(),
            timeout: 15,
            logPath: $this->directory . '/server.log',
            processGroup: true,
        ));
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            clearstatcache(true, $ready);
            if (is_file($ready)) {
                return json_decode((string) file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR)['port'];
            }
            usleep(10_000);
        }
        self::fail('Fixture did not become ready: ' . file_get_contents($this->directory . '/server.log'));
    }

    private function dns(): int
    {
        return $this->start([PHP_BINARY, dirname(__DIR__, 3) . '/tests/Fixtures/Http/dns-server.php', '--ready-file']);
    }

    private static function question(string $host, int $kind = 1, int $family = 1): string
    {
        $labels = '';
        foreach (explode('.', $host) as $label) {
            $labels .= chr(strlen($label)) . $label;
        }
        return pack('n6', 0x1234, 0x0100, 1, 0, 0, 0) . $labels . "\0" . pack('nn', $kind, $family);
    }

    /** @return resource */
    private function socket(int $port, string $transport = 'udp')
    {
        $socket = stream_socket_client($transport . '://127.0.0.1:' . $port, $error, $message, 1);
        self::assertIsResource($socket, $message);
        stream_set_timeout($socket, 1);
        return $socket;
    }

    private function query(int $port, string $host, int $kind = 1, int $family = 1, bool $tcp = false): string
    {
        $socket = $this->socket($port, $tcp ? 'tcp' : 'udp');
        try {
            $packet = self::question($host, $kind, $family);
            if ($tcp) {
                $framed = pack('n', strlen($packet)) . $packet;
                fwrite($socket, $framed[0]);
                usleep(5000);
                fwrite($socket, substr($framed, 1));
                $length = unpack('nlength', $this->read($socket, 2))['length'];
                return $this->read($socket, $length);
            }
            fwrite($socket, $packet);
            return (string) fread($socket, 65_535);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $stream */
    private function read($stream, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = fread($stream, $length - strlen($result));
            self::assertNotFalse($chunk);
            self::assertNotSame('', $chunk, 'Fixture closed or stalled its TCP response');
            $result .= $chunk;
        }
        return $result;
    }

    public function testDnsServesBothAddressFamiliesAndPrivateAnswers(): void
    {
        $port = $this->dns();
        $a = $this->query($port, 'BOTH.BAANDER.APP');
        $aaaa = $this->query($port, 'both.baander.app', 28);
        self::assertSame(0x1234, unpack('nid', $a)['id']);
        self::assertSame(0x8180, unpack('nflags', substr($a, 2))['flags']);
        self::assertSame(1, unpack('ncount', substr($a, 6))['count']);
        self::assertSame(inet_pton('8.8.8.8'), substr($a, -4));
        self::assertSame(inet_pton('2001:4860:4860::8888'), substr($aaaa, -16));
        self::assertSame(inet_pton('fd00::1'), substr($this->query($port, 'private6.baander.app', 28), -16));
    }

    public function testDnsDistinguishesNodataServfailAndRejectedNames(): void
    {
        $port = $this->dns();
        foreach ([
            ['nodata.baander.app', 28, 1, 0],
            ['family-error.baander.app', 28, 1, 2],
            ['baander.app', 1, 1, 3],
            ['both.baander.app', 1, 3, 3],
        ] as [$host, $kind, $family, $code]) {
            $packet = $this->query($port, $host, $kind, $family);
            self::assertSame($code, unpack('nflags', substr($packet, 2))['flags'] & 0xf);
            self::assertSame(0, unpack('nanswers', substr($packet, 6))['answers']);
        }
    }

    public function testDnsUdpTruncationFallsBackToCompleteTcpAnswers(): void
    {
        $port = $this->dns();
        $udp = $this->query($port, 'many.baander.app');
        self::assertSame(0x0200, unpack('nflags', substr($udp, 2))['flags'] & 0x0200);
        self::assertSame(0, unpack('nanswers', substr($udp, 6))['answers']);
        $tcp = $this->query($port, 'many.baander.app', tcp: true);
        self::assertSame(101, unpack('nanswers', substr($tcp, 6))['answers']);
        self::assertSame(inet_pton('10.0.0.1'), substr($tcp, -4));
        self::assertGreaterThan(512, strlen($tcp));
    }

    public function testStalledAndMalformedDnsQueriesDoNotBlockOtherClients(): void
    {
        $port = $this->dns();
        $stalled = $this->socket($port);
        stream_set_timeout($stalled, 0, 100_000);
        fwrite($stalled, self::question('stall.baander.app'));
        self::assertSame('', (string) fread($stalled, 65_535));
        fwrite($stalled, 'malformed');
        self::assertSame('', (string) fread($stalled, 65_535));
        fclose($stalled);
        self::assertSame(inet_pton('8.8.8.8'), substr($this->query($port, 'both.baander.app'), -4));
    }

    public function testDelayedOrphanAnswerDoesNotDelayOtherDnsClients(): void
    {
        $port = $this->dns();
        $orphan = $this->socket($port);
        $started = hrtime(true) / 1e9;
        fwrite($orphan, self::question('orphan.baander.app'));
        self::assertSame(inet_pton('8.8.8.8'), substr($this->query($port, 'both.baander.app'), -4));
        self::assertLessThan(0.25, hrtime(true) / 1e9 - $started);
        self::assertSame(inet_pton('8.8.8.8'), substr((string) fread($orphan, 65_535), -4));
        self::assertGreaterThanOrEqual(0.25, hrtime(true) / 1e9 - $started);
        fclose($orphan);
    }

    public function testEchoServerPreservesRequestPathHeadersAndWebsocketHandshake(): void
    {
        $ready = $this->directory . '/echo-ready';
        $this->processes[] = $this->runner->start(new ProcessSpec(
            argv: [PHP_BINARY, dirname(__DIR__, 2) . '/test-websocket-proxy-logs.php', '--echo', $ready, '0'],
            environment: getenv(),
            timeout: 15,
            logPath: $this->directory . '/echo.log',
            processGroup: true,
        ));
        for ($attempt = 0; $attempt < 100 && !is_file($ready); ++$attempt) {
            clearstatcache(true, $ready);
            usleep(10_000);
        }
        self::assertFileExists($ready, (string) file_get_contents($this->directory . '/echo.log'));
        $port = json_decode((string) file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR)['port'];
        $incomplete = $this->socket($port, 'tcp');
        fwrite($incomplete, "GET /slow HTTP/1.1\r\n");
        $started = hrtime(true) / 1e9;
        $socket = $this->socket($port, 'tcp');
        fwrite(
            $socket,
            "GET /api/ws?ticket=encoded%20value HTTP/1.1\r\nHost: proxy.baander.app:18443\r\nX-Forwarded-Proto: https\r\n\r\n",
        );
        $response = (string) stream_get_contents($socket);
        fclose($socket);
        fclose($incomplete);
        self::assertLessThan(0.25, hrtime(true) / 1e9 - $started);
        [$headers, $body] = explode("\r\n\r\n", $response, 2);
        self::assertStringContainsString('200 OK', $headers);
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/api/ws?ticket=encoded%20value', $decoded['path']);
        self::assertSame('proxy.baander.app:18443', $decoded['headers']['Host']);
        self::assertSame('https', $decoded['headers']['X-Forwarded-Proto']);
        $socket = $this->socket($port, 'tcp');
        fwrite(
            $socket,
            "GET /api/ws HTTP/1.1\r\nHost: proxy.baander.app\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n",
        );
        $handshake = (string) stream_get_contents($socket);
        fclose($socket);
        self::assertStringContainsString('101 Switching Protocols', $handshake);
        self::assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $handshake);
    }

    public function testSupervisorAdoptsAndReapsARealAlarmBoundedOrphan(): void
    {
        $subject = $this->directory . '/parent.php';
        file_put_contents(
            $subject,
            <<<'PHP_SUBJECT'
            <?php
            $libc = FFI::cdef('int fork(void); unsigned int alarm(unsigned int seconds); int kill(int pid, int sig);');
            $child = $libc->fork();
            if ($child === 0) {
                $libc->alarm(1);
                sleep(3);
                exit(8);
            }
            echo $child, "\n";
            fflush(STDOUT);
            $libc->kill(getmypid(), 9);
            PHP_SUBJECT,
        );
        $result = $this->runner->run(new ProcessSpec(
            argv: [
                PHP_BINARY,
                dirname(__DIR__, 3) . '/tests/Fixtures/Http/dns-orphan-supervisor.php',
                PHP_BINARY,
                $subject,
            ],
            environment: getenv(),
            timeout: 7,
            processGroup: true,
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame(
            ['parent_signal' => 9, 'helper_signal' => 14, 'reaped' => true],
            json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testSupervisorRejectsAParentThatDoesNotDieAbruptly(): void
    {
        $subject = $this->directory . '/parent.php';
        file_put_contents($subject, '<?php echo "123\\n";');
        $result = $this->runner->run(new ProcessSpec(
            argv: [
                PHP_BINARY,
                dirname(__DIR__, 3) . '/tests/Fixtures/Http/dns-orphan-supervisor.php',
                PHP_BINARY,
                $subject,
            ],
            environment: getenv(),
            timeout: 5,
            processGroup: true,
        ));
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Parent did not exit abruptly', $result->stderr);
    }

    public function testSupervisorRejectsAnOrphanThatExitsWithoutItsAlarm(): void
    {
        $subject = $this->directory . '/parent.php';
        file_put_contents(
            $subject,
            <<<'PHP_SUBJECT'
            <?php
            $libc = FFI::cdef('int fork(void); int kill(int pid, int sig);');
            $child = $libc->fork();
            if ($child === 0) {
                usleep(300000);
                exit(0);
            }
            echo $child, "\n";
            fflush(STDOUT);
            $libc->kill(getmypid(), 9);
            PHP_SUBJECT,
        );
        $result = $this->runner->run(new ProcessSpec(
            argv: [
                PHP_BINARY,
                dirname(__DIR__, 3) . '/tests/Fixtures/Http/dns-orphan-supervisor.php',
                PHP_BINARY,
                $subject,
            ],
            environment: getenv(),
            timeout: 5,
            processGroup: true,
        ));
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Helper did not exit from its own alarm', $result->stderr);
    }

    public function testInterruptedSupervisorReapsItsAdoptedHelper(): void
    {
        $subject = $this->directory . '/parent.php';
        $marker = $this->directory . '/helper.pid';
        $program = <<<'PHP_SUBJECT'
            <?php
            $libc = FFI::cdef('int fork(void); int kill(int pid, int sig);');
            $child = $libc->fork();
            if ($child === 0) {
                fclose(STDOUT);
                fclose(STDERR);
                sleep(8);
                exit(0);
            }
            file_put_contents(MARKER_PATH, (string) $child);
            echo $child, "\n";
            fflush(STDOUT);
            $libc->kill(getmypid(), 9);
            PHP_SUBJECT;
        file_put_contents($subject, str_replace('MARKER_PATH', var_export($marker, true), $program));
        $process = $this->runner->start(new ProcessSpec(
            argv: [
                PHP_BINARY,
                dirname(__DIR__, 3) . '/tests/Fixtures/Http/dns-orphan-supervisor.php',
                PHP_BINARY,
                $subject,
            ],
            environment: getenv(),
            timeout: 5,
        ));
        for ($attempt = 0; $attempt < 100 && !is_file($marker); ++$attempt) {
            clearstatcache(true, $marker);
            usleep(10_000);
        }
        self::assertFileExists($marker);
        usleep(200_000);
        $process->cancel();
        $result = $this->runner->wait($process);
        self::assertSame(1, $result->exitCode, $result->stderr);
        self::assertStringContainsString('DNS orphan qualification interrupted', $result->stderr);
        self::assertFileDoesNotExist('/proc/' . file_get_contents($marker));
    }

    /** @return iterable<string,array{string}> */
    public static function logControls(): iterable
    {
        foreach ([
            'credential access',
            'credential errors',
            'credential global',
            'missing upgrades',
            'missing redirects',
            'missing ordinary diagnostics',
            'raw upgrade errors',
            'query access',
        ] as $control) {
            yield $control => [$control];
        }
    }

    #[DataProvider('logControls')]
    public function testProxyLogNegativeControlsAreBlocking(string $control): void
    {
        $access = str_repeat('"GET /api/ws HTTP/1.1" 502 ' . "\n", 4) . str_repeat('"GET /api/ws HTTP/1.1" 307 ' . "\n", 4);
        $errors = '/api/proxy-observability-control';
        $global = '';
        match ($control) {
            'credential access' => $access .= 'secret-canary',
            'credential errors' => $errors .= 'secret-canary',
            'credential global' => $global .= 'secret-canary',
            'missing upgrades' => $access = str_replace('502 ', '500 ', $access),
            'missing redirects' => $access = str_replace('307 ', '308 ', $access),
            'missing ordinary diagnostics' => $errors = '',
            'raw upgrade errors' => $global = '/api/ws',
            'query access' => $access .= '?ticket=',
            default => throw new RuntimeException('Unknown log control'),
        };
        $this->expectException(RuntimeException::class);
        ProxyQualification::assertLogs('dev', ['secret-canary'], $access, $errors, $global);
    }

    public function testSafeProxyLogsPassBothHttpAndHttpsVariants(): void
    {
        $access = str_repeat('"GET /api/ws HTTP/1.1" 502 ' . "\n", 4);
        ProxyQualification::assertLogs('prod', ['secret-canary'], $access, '/api/proxy-observability-control', '');
        ProxyQualification::assertLogs(
            'template',
            ['secret-canary'],
            $access . str_repeat('"GET /api/ws HTTP/1.1" 307 ' . "\n", 4),
            '/api/proxy-observability-control',
            '',
        );
        $this->addToAssertionCount(2);
    }

    /** @return iterable<string,array{string,int}> */
    public static function containerOutcomes(): iterable
    {
        yield 'successful cleanup' => ['success', 0];
        yield 'remote published topology rejected before creation' => ['remote-target', 1];
        yield 'failed removal' => ['cleanup-failure', 1];
        yield 'cleanup budget retains recovery receipt' => ['cleanup-budget', 1];
        yield 'cancellation during execution and cleanup' => ['cancellation', 143];
        yield 'command timeout' => ['timeout', 124];
        yield 'zero exit after timeout' => ['timeout-zero', 124];
        yield 'primary failure survives cleanup failure' => ['primary-cleanup-failure', 7];
        yield 'command exit status' => ['command-failure', 7];
    }

    #[DataProvider('containerOutcomes')]
    public function testContainerDrillRecordsCleanupAndPreservesItsFailure(string $mode, int $status): void
    {
        $docker = $this->directory . '/docker';
        copy(__DIR__ . '/Fixtures/http-qualification-docker.php', $docker);
        chmod($docker, 0700);
        $harness = $this->directory . '/qualification.php';
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        file_put_contents(
            $harness,
            '<?php require ' . $autoload . ';' . <<<'PHP_HARNESS'
            exit(Baander\Tooling\Fixtures\Http\ContainerQualification::run(
                new Baander\Tooling\Fixtures\Runtime(
                    getcwd(),
                    configuration: getenv('HTTP_FIXTURE_MODE') === 'cleanup-budget'
                        ? Baander\Tooling\Configuration\ToolingConfiguration::forRepository(getcwd(), getenv(), ['budgets' => ['cleanup' => 0.05]])
                        : null,
                    budget: 'http',
                ),
                'baander-http-fixture',
                ['fixture-image', 'sleep'],
                static function (Baander\Tooling\Fixtures\Runtime $drill): void {
                    if (getenv('HTTP_FIXTURE_MODE') === 'timeout') {
                        $drill->run([PHP_BINARY, '-r', 'usleep(2000000);'], timeout: 0.05);
                    } elseif (getenv('HTTP_FIXTURE_MODE') === 'timeout-zero') {
                        $drill->run(['bash', '-c', 'trap "exit 0" TERM; while true; do sleep 0.02; done'], timeout: 0.05, check: false);
                    } elseif (in_array(getenv('HTTP_FIXTURE_MODE'), ['command-failure', 'primary-cleanup-failure'], true)) {
                        $drill->run([PHP_BINARY, '-r', 'exit(7);']);
                    }
                },
                [],
            ));
            PHP_HARNESS,
        );
        $result = $this->runner->run(new ProcessSpec(
            argv: [PHP_BINARY, $harness],
            environment: array_replace(
                getenv(),
                [
                    'PATH' => $this->directory . ':' . getenv('PATH'),
                    'HTTP_FIXTURE_MARKER' => $this->directory . '/owned',
                    'HTTP_FIXTURE_MODE' => $mode,
                    'BAANDER_DOCKER_EXECUTABLE' => $docker,
                    'DOCKER_HOST' => $mode === 'remote-target' ? 'tcp://127.0.0.1:2375' : 'unix:///http-fixture.sock',
                    'HTTP_FIXTURE_ENDPOINT' => $mode === 'remote-target' ? 'tcp://127.0.0.1:2375' : 'unix:///http-fixture.sock',
                    'DOCKER_CONTEXT' => '',
                    'BAANDER_TEST_DOCKER_CONTEXT' => '',
                    'BAANDER_TOOLING_TARGET_FILE' => '',
                    'BAANDER_TEST_ARTIFACTS' => $this->directory . '/artifacts',
                    'HTTP_FIXTURE_COMMANDS' => $this->directory . '/commands',
                ],
            ),
            timeout: 10,
            processGroup: true,
        ));
        self::assertSame($status, $result->exitCode, $result->stderr);
        self::assertSame(1, preg_match('/HTTP fixture artifacts: (.+)/', $result->stdout, $match));
        $this->artifacts[] = $match[1];
        $receipt = json_decode((string) file_get_contents($match[1] . '/results.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($status, $receipt['status']);
        if ($mode === 'remote-target') {
            self::assertStringContainsString('host-local published-port access', $receipt['error']);
            self::assertFileDoesNotExist($match[1] . '/resources.json');
            self::assertStringNotContainsString('create', file_get_contents($this->directory . '/commands'));
            return;
        }
        if ($mode === 'cleanup-budget') {
            self::assertNotEmpty($receipt['cleanup_errors']);
            self::assertFileExists($match[1] . '/resources.json');
            self::assertFileExists($this->directory . '/owned');
            self::assertStringNotContainsString('"rm"', file_get_contents($this->directory . '/commands'));
            return;
        }
        self::assertSame('retained fixture log', file_get_contents($match[1] . '/container.log'));
        if (in_array($mode, ['cleanup-failure', 'primary-cleanup-failure'], true)) {
            self::assertNotEmpty($receipt['cleanup_errors']);
            self::assertSame([$receipt['container']], $receipt['remaining_containers']);
            self::assertFileExists($match[1] . '/resources.json');
        } else {
            self::assertSame([], $receipt['cleanup_errors']);
            self::assertSame([], $receipt['remaining_containers']);
            self::assertFileDoesNotExist($this->directory . '/owned');
        }
        self::assertStringNotContainsString('compose', file_get_contents($this->directory . '/commands'));
        if ($mode === 'cancellation') {
            self::assertNotNull($receipt['error']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function forwardedHeaderControls(): iterable
    {
        foreach (['route', 'host', 'forwarded host', 'scheme', 'port'] as $control) {
            yield $control => [$control];
        }
    }

    #[DataProvider('forwardedHeaderControls')]
    public function testSpoofedOrLostProxyOriginHeadersAreBlocking(string $control): void
    {
        $response = [
            'path' => '/api/ws',
            'headers' => [
                'Host' => 'proxy.baander.app:18443',
                'X-Forwarded-Host' => 'proxy.baander.app:18443',
                'X-Forwarded-Proto' => 'https',
            ],
        ];
        match ($control) {
            'route' => $response['path'] = '/',
            'host' => $response['headers']['Host'] = 'attacker.baander.app',
            'forwarded host' => $response['headers']['X-Forwarded-Host'] = 'attacker.baander.app',
            'scheme' => $response['headers']['X-Forwarded-Proto'] = 'http',
            'port' => $response['headers']['X-Forwarded-Port'] = '9443',
            default => throw new RuntimeException('Unknown header control'),
        };
        $this->expectException(RuntimeException::class);
        ProxyQualification::assertEcho('proxy.baander.app:18443', '/api/ws', $response);
    }
}
