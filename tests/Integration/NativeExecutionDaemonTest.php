<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\NativeExecutionClient;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use PHPUnit\Framework\TestCase;

final class NativeExecutionDaemonTest extends TestCase
{
    private string $directory;
    /** @var resource|null */
    private $daemon = null;
    private NativeExecutionClient $client;

    protected function setUp(): void
    {
        if (!extension_loaded('posix')) {
            self::markTestSkipped('Requires the native CI image with ext-posix.');
        }
        $this->directory = sys_get_temp_dir() . '/baander-native-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $environment = [...getenv(), 'BAANDER_NATIVE_SOCKET' => $this->directory . '/native.sock',
            'BAANDER_WORKER_NAMESPACE' => 'baander.app:workers', 'BAANDER_WORKER_BOOT_ID' => str_repeat('a', 32), 'BAANDER_WORKER_LEASE_EPOCH' => '1'];
        $daemon = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker-native.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/log', 'a'], 2 => ['file', $this->directory . '/log', 'a']], $pipes, null, $environment);
        self::assertIsResource($daemon);
        $this->daemon = $daemon;
        $this->client = $this->newClient();
        $deadline = microtime(true) + 2;
        do {
            if (file_exists($this->directory . '/native.sock')) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline && proc_get_status($this->daemon)['running']);
        self::fail('Native daemon did not become ready: ' . file_get_contents($this->directory . '/log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->daemon)) {
            proc_terminate($this->daemon);
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->daemon)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            proc_terminate($this->daemon, SIGKILL);
            proc_close($this->daemon);
        }
        if (!isset($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testSubmitReplayRetainsResultAndReleaseNeverRelaunches(): void
    {
        self::assertTrue($this->client->handshake()['ready']);
        $first = $this->client->submit(['ffmpeg', '-version'], 5, 1);
        $retry = $this->newClient()->submit(['ffmpeg', '-version'], 5, 1);
        self::assertSame($first->token, $retry->token);
        $result = $this->finish($first);
        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('ffmpeg version', $result['stdout']);
        self::assertSame($result['stdout'], $this->client->inspect($first)['stdout']);
        self::assertTrue($this->client->release($first));
        self::assertSame('unknown', $this->client->inspect($first)['state']);
        $this->expectException(NativeExecutionException::class);
        $this->newClient()->submit(['ffmpeg', '-version'], 5, 1);
    }

    public function testSignalsFenceHandlesAndStopRequiresTerminalReceipt(): void
    {
        $handle = $this->client->submit(['ffmpeg', '-nostdin', '-re', '-f', 'lavfi', '-i', 'anullsrc', '-f', 'null', '-'], 10);
        self::assertSame('running', $this->client->signal($handle, 'pause')['state']);
        self::assertSame('running', $this->client->signal($handle, 'resume')['state']);
        self::assertSame('unknown', $this->client->signal(new NativeExecutionHandle(str_repeat('b', 64), $handle->attempt), 'stop')['state']);
        self::assertFalse($this->client->release($handle));
        $this->client->signal($handle, 'stop');
        self::assertSame('exited', $this->finish($handle)['state']);
        self::assertTrue($this->client->release($handle));
    }

    public function testOnlyFixedNativeExecutablesAreAccepted(): void
    {
        try {
            $this->client->submit(['/bin/sh', '-c', 'exit 0']);
            self::fail('An arbitrary executable was admitted.');
        } catch (NativeExecutionException $error) {
            self::assertFalse($error->uncertain);
        }
        self::assertTrue($this->client->handshake()['ready']);
    }

    public function testTermWithActiveFfmpegLeavesNoLiveOwnedProcessGroupAndRemovesSocket(): void
    {
        if (!is_readable('/proc/self/stat')) {
            self::markTestSkipped('Requires Linux process identity observations.');
        }
        $daemonPid = proc_get_status($this->daemon)['pid'];
        $handle = $this->client->submit(['ffmpeg', '-nostdin', '-threads', '1', '-filter_threads', '1',
            '-re', '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono', '-threads', '1', '-f', 'null', '-'], 20);
        $owned = null;
        try {
            $deadline = microtime(true) + 3;
            do {
                foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
                    $pid = (int) basename(dirname($path));
                    $process = $this->processObservation($pid);
                    if ($process !== null && $process['parent'] === $daemonPid && $process['group'] === $pid
                        && !in_array($process['state'], ['Z', 'X'], true) && @readlink('/proc/' . $pid . '/exe') === '/usr/bin/ffmpeg'
                    ) {
                        $owned = ['pid' => $pid, ...$process];
                        break;
                    }
                }
                if ($owned !== null) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertNotNull($owned, 'An independently observed FFmpeg child must own a live process group before TERM.');
            self::assertNotSame(posix_getpgrp(), $owned['group']);
            self::assertGreaterThan(0, (int) $owned['startTicks']);
            self::assertNotEmpty($this->liveProcessGroup($owned['group']));
            self::assertSame('running', $this->client->inspect($handle)['state']);
            self::assertTrue(proc_terminate($this->daemon, SIGTERM));

            $deadline = microtime(true) + 3;
            do {
                $status = proc_get_status($this->daemon);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'TERM must drain the active native daemon within the fixture deadline.');
            self::assertSame(0, $status['exitcode']);
            self::assertSame([], $this->liveProcessGroup($owned['group']), 'Daemon exit must leave no live member of the observed owned group.');
            $remaining = $this->processObservation($owned['pid']);
            self::assertFalse($remaining !== null && $remaining['startTicks'] === $owned['startTicks']
                && !in_array($remaining['state'], ['Z', 'X'], true), 'The captured FFmpeg process identity must no longer be live.');
            clearstatcache(true, $this->directory . '/native.sock');
            self::assertFileDoesNotExist($this->directory . '/native.sock');
        } finally {
            // Cleanup follows the containment assertions and targets only the captured identity.
            if ($owned !== null) {
                $remaining = $this->processObservation($owned['pid']);
                if ($remaining !== null && $remaining['startTicks'] === $owned['startTicks'] && $remaining['group'] === $owned['group']) {
                    @posix_kill(-$owned['group'], SIGKILL);
                }
            }
        }
    }

    /** @return array{parent:int, group:int, state:string, startTicks:string}|null */
    private function processObservation(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return null;
        }
        $end = strrpos($stat, ') ');
        if ($end === false) {
            return null;
        }
        $fields = explode(' ', substr($stat, $end + 2));

        return ['parent' => (int) $fields[1], 'group' => (int) $fields[2], 'state' => $fields[0], 'startTicks' => $fields[19]];
    }

    /** @return list<array{pid:int, startTicks:string}> */
    private function liveProcessGroup(int $group): array
    {
        $live = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $pid = (int) basename(dirname($path));
            $process = $this->processObservation($pid);
            if ($process !== null && $process['group'] === $group && !in_array($process['state'], ['Z', 'X'], true)) {
                $live[] = ['pid' => $pid, 'startTicks' => $process['startTicks']];
            }
        }

        return $live;
    }

    public function testWrongBootCannotInspectOrSignalCurrentAttempt(): void
    {
        $wrong = new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('b', 32), 1, 'media', 1);
        $this->expectException(NativeExecutionException::class);
        $wrong->handshake();
    }

    public function testLostSubmissionReplyRecoversTheSameAttempt(): void
    {
        $socket = stream_socket_client('unix://' . $this->directory . '/native.sock', $errno, $error, 1);
        self::assertIsResource($socket);
        $request = ['id' => str_repeat('c', 32), 'namespace' => 'baander.app:workers', 'bootId' => str_repeat('a', 32),
            'epoch' => 1, 'clientId' => 'media', 'generation' => 1, 'operation' => 'submit', 'attempt' => 1,
            'argv' => ['ffmpeg', '-version'], 'timeout' => 5];
        $frame = json_encode($request, JSON_THROW_ON_ERROR) . "\n";
        self::assertSame(strlen($frame), fwrite($socket, $frame));
        // The server must handle a committed launch whose client disappeared before reading.
        fclose($socket);
        usleep(30_000);
        $retry = $this->client->submit(['ffmpeg', '-version'], 5, 1);
        $secondRetry = $this->newClient()->submit(['ffmpeg', '-version'], 5, 1);
        self::assertSame($retry->token, $secondRetry->token);
        self::assertSame(0, $this->finish($retry)['exitCode']);
    }

    public function testReleasedAndRejectedAttemptsRemainFencedAcrossReconnect(): void
    {
        try {
            $this->client->submit(['/bin/sh'], 5, 10);
            self::fail('Disallowed executable must be rejected.');
        } catch (NativeExecutionException $error) {
            self::assertFalse($error->uncertain);
        }
        try {
            $this->newClient()->submit(['ffmpeg', '-version'], 5, 10);
            self::fail('A rejected attempt must never become a fresh command.');
        } catch (NativeExecutionException $error) {
            self::assertTrue($error->uncertain);
        }
        $newer = $this->client->submit(['ffmpeg', '-version'], 5, 11);
        self::assertSame(0, $this->finish($newer)['exitCode']);
    }

    public function testGenerationAdvanceFencesOldHandlesAndClientRequests(): void
    {
        $old = $this->client->submit(['ffmpeg', '-version'], 5);
        $this->finish($old);
        $replacement = new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('a', 32), 1, 'media', 2);
        self::assertTrue($replacement->handshake()['ready']);
        self::assertSame('unknown', $replacement->signal($old, 'stop')['state']);
        $this->expectException(NativeExecutionException::class);
        $this->client->inspect($old);
    }

    public function testExecutionTimeoutReturnsTerminalFailureWithoutBlockingControl(): void
    {
        $handle = $this->client->submit(['ffmpeg', '-nostdin', '-re', '-f', 'lavfi', '-i', 'anullsrc', '-f', 'null', '-'], 0.15);
        self::assertTrue($this->client->handshake()['ready']);
        $result = $this->finish($handle);
        self::assertSame('Native execution deadline exceeded.', $result['error']);
        self::assertNotSame(0, $result['exitCode']);
        self::assertTrue($this->client->release($handle));
    }

    public function testOutputOverflowFailsTheLauncherInsteadOfReturningTruncatedProbeJson(): void
    {
        $this->client->submit(['ffprobe', '-f', 'lavfi', '-i', 'anullsrc', '-show_packets', '-of', 'json'], 10);
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($this->daemon);
            if (!$status['running']) {
                self::assertSame(1, $status['exitcode']);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('Output overflow must terminate the supervised launcher.');
    }

    public function testClientLedgerIsBoundedWithoutForgettingReleasedClientIdentities(): void
    {
        for ($index = 0; $index < 64; ++$index) {
            $client = new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('a', 32), 1, 'slot-' . $index, 1);
            self::assertTrue($client->handshake()['ready']);
        }
        try {
            $this->client->handshake();
            self::fail('A full client ledger cannot accept a new identity.');
        } catch (NativeExecutionException $error) {
            self::assertFalse($error->uncertain);
        }
        $known = new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('a', 32), 1, 'slot-0', 1);
        self::assertTrue($known->handshake()['ready']);
    }

    public function testGenerationCannotReplaceAnUnreapedRunningCommand(): void
    {
        $this->client->submit(['ffmpeg', '-nostdin', '-re', '-f', 'lavfi', '-i', 'anullsrc', '-f', 'null', '-'], 10);
        $replacement = new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('a', 32), 1, 'media', 2);
        try {
            $replacement->handshake();
            self::fail('A replacement generation cannot supersede owned native work.');
        } catch (NativeExecutionException $error) {
            self::assertTrue($error->uncertain);
        }
        $deadline = microtime(true) + 2;
        do {
            $status = proc_get_status($this->daemon);
            if (!$status['running']) {
                self::assertSame(1, $status['exitcode']);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('A lost client owner must drain its launcher.');
    }

    public function testStableClientCanRunMoreGenerationsThanTheClientLedgerLimit(): void
    {
        $first = null;
        for ($generation = 1; $generation <= 66; ++$generation) {
            $this->client = new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('a', 32), 1, 'media-slot-0', $generation);
            $handle = $this->client->submit(['ffprobe', '-version'], 5);
            $first ??= $handle;
            self::assertSame(0, $this->finish($handle)['exitCode']);
            self::assertTrue($this->client->release($handle));
        }
        self::assertSame('unknown', $this->client->signal($first, 'stop')['state']);
        self::assertTrue($this->client->handshake()['ready']);
    }

    public function testEightActiveCommandsRejectNinthWithoutBreakingReplayOrReusingRejectedAttempt(): void
    {
        $argv = ['ffmpeg', '-nostdin', '-threads', '1', '-filter_threads', '1', '-filter_complex_threads', '1',
            '-re', '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono', '-threads', '1', '-f', 'null', '-'];
        $handles = [];
        for ($sequence = 1; $sequence <= 8; ++$sequence) {
            $handle = $this->client->submit($argv, 20, $sequence);
            $handles[] = $handle;
            self::assertSame('running', $this->client->signal($handle, 'pause')['state']);
        }
        foreach ($handles as $handle) {
            self::assertSame('running', $this->client->inspect($handle)['state']);
        }
        self::assertSame($handles[0]->token, $this->newClient()->submit($argv, 20, 1)->token);
        try {
            $this->newClient()->submit($argv, 21, 1);
            self::fail('An exact retry cannot change the original execution deadline.');
        } catch (NativeExecutionException $error) {
            self::assertTrue($error->uncertain);
            self::assertStringContainsString('different command', $error->getMessage());
        }
        try {
            $this->client->submit($argv, 20, 9);
            self::fail('A ninth active command must be rejected.');
        } catch (NativeExecutionException $error) {
            self::assertFalse($error->uncertain);
            self::assertStringContainsString('capacity', $error->getMessage());
        }
        self::assertTrue($this->client->handshake()['ready']);
        $first = array_shift($handles);
        $this->client->signal($first, 'stop');
        self::assertSame('exited', $this->finish($first)['state']);
        self::assertTrue($this->client->release($first));
        try {
            $this->newClient()->submit($argv, 20, 9);
            self::fail('Capacity recovery must not turn a rejected attempt into a fresh launch.');
        } catch (NativeExecutionException $error) {
            self::assertTrue($error->uncertain);
            self::assertStringContainsString('rejected', $error->getMessage());
        }
        $handles[] = $this->client->submit($argv, 20, 10);
        foreach ($handles as $handle) {
            $this->client->signal($handle, 'stop');
        }
        foreach ($handles as $handle) {
            self::assertSame('exited', $this->finish($handle)['state']);
            self::assertTrue($this->client->release($handle));
        }
    }

    public function testThirtyTwoTerminalResultsRemainReadableUntilReleaseReopensCapacity(): void
    {
        $handles = [];
        for ($sequence = 1; $sequence <= 32; ++$sequence) {
            $handle = $this->client->submit(['ffmpeg', '-version'], 5, $sequence);
            self::assertSame(0, $this->finish($handle)['exitCode']);
            $handles[] = $handle;
        }
        foreach ($handles as $handle) {
            $snapshot = $this->client->inspect($handle);
            self::assertSame('exited', $snapshot['state']);
            self::assertStringContainsString('ffmpeg version', $snapshot['stdout']);
        }
        self::assertSame($handles[0]->token, $this->newClient()->submit(['ffmpeg', '-version'], 5, 1)->token);
        try {
            $this->client->submit(['ffmpeg', '-version'], 5, 33);
            self::fail('Unreleased terminal results must exhaust bounded retention.');
        } catch (NativeExecutionException $error) {
            self::assertFalse($error->uncertain);
            self::assertStringContainsString('capacity', $error->getMessage());
        }
        $released = $handles[0];
        self::assertTrue($this->client->release($released));
        self::assertSame('unknown', $this->client->inspect($released)['state']);
        foreach ([1, 33] as $burnedAttempt) {
            try {
                $this->newClient()->submit(['ffmpeg', '-version'], 5, $burnedAttempt);
                self::fail('Released and rejected attempt identities must remain fenced.');
            } catch (NativeExecutionException $error) {
                self::assertTrue($error->uncertain);
            }
        }
        $replacement = $this->client->submit(['ffmpeg', '-version'], 5, 34);
        self::assertSame(0, $this->finish($replacement)['exitCode']);
        self::assertStringContainsString('ffmpeg version', $this->client->inspect($handles[31])['stdout']);
        self::assertTrue($this->client->release($replacement));
    }

    public function testDirectoryCreationLoserAcceptsPrivateDirectoryCreatedByPeer(): void
    {
        $code = <<<'PHP'
namespace App\Shared\Infrastructure\Worker;
function mkdir(string $path, int $mode, bool $recursive): bool
{
    // The peer wins after the launcher's initial is_dir check.
    if (!\mkdir($path, $mode, $recursive)) { throw new \RuntimeException('Peer failed to create directory.'); }
    return false;
}
require $argv[1] . '/vendor/autoload.php';
try {
    exit((new NativeExecutionDaemon('baander.app:workers', str_repeat('a', 32), 1))->run($argv[2]));
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}
PHP;
        $directory = $this->directory . '/peer-boot';
        $socketPath = $directory . '/native.sock';
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2), $socketPath],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/peer-log', 'a'], 2 => ['file', $this->directory . '/peer-log', 'a']], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 2;
            while (!file_exists($socketPath) && proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($socketPath, (string) file_get_contents($this->directory . '/peer-log'));
            $peer = new NativeExecutionClient($socketPath, 'baander.app:workers', str_repeat('a', 32), 1, 'peer', 1);
            self::assertTrue($peer->handshake()['ready']);
        } finally {
            proc_terminate($process);
            $deadline = microtime(true) + 2;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            proc_terminate($process, SIGKILL);
            proc_close($process);
            if (file_exists($socketPath)) { unlink($socketPath); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }

    private function newClient(): NativeExecutionClient
    {
        return new NativeExecutionClient($this->directory . '/native.sock', 'baander.app:workers', str_repeat('a', 32), 1, 'media', 1);
    }

    /** @return array<string, mixed> */
    private function finish(NativeExecutionHandle $handle): array
    {
        $deadline = microtime(true) + 3;
        do {
            $result = $this->client->inspect($handle);
            if ($result['state'] === 'exited') {
                return $result;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('Native attempt did not become terminal.');
    }
}
