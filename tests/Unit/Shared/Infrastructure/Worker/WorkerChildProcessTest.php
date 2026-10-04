<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use PHPUnit\Framework\TestCase;

final class WorkerChildProcessTest extends TestCase
{
    /** @var resource */
    private mixed $stdout;
    /** @var resource */
    private mixed $stderr;
    /** @var list<WorkerChildProcess> */
    private array $children = [];

    protected function setUp(): void
    {
        $this->stdout = tmpfile();
        $this->stderr = tmpfile();
    }

    protected function tearDown(): void
    {
        array_splice($this->children, 0);
        fclose($this->stdout);
        fclose($this->stderr);
    }

    public function testFreshProcessPreservesArgumentsEnvironmentOutputAndExitStatus(): void
    {
        $literal = '$(touch /tmp/baander.app-shell-must-not-run); literal';
        $child = $this->start('fwrite(STDOUT, $argv[1]); fwrite(STDERR, getenv("BAANDER_CHILD_TEST")); exit(23);', [$literal], ['BAANDER_CHILD_TEST' => 'baander.app']);
        $this->awaitExit($child);

        self::assertSame($literal, file_get_contents(stream_get_meta_data($this->stdout)['uri']));
        self::assertSame('baander.app', file_get_contents(stream_get_meta_data($this->stderr)['uri']));
        self::assertSame(23, $child->exitCode());
        self::assertNull($child->terminationSignal());
        self::assertFalse($child->poll(hrtime(true) / 1e9));
        self::assertSame(23, $child->exitCode());
        self::assertNotSame(getmypid(), $child->pid());
    }

    public function testCooperativeChildDrainsOnTerm(): void
    {
        $child = $this->start('pcntl_async_signals(true); pcntl_signal(SIGTERM, static function () { exit(0); }); echo "ready"; while (true) { usleep(10000); }');
        $this->awaitReady();
        $child->requestStop(hrtime(true) / 1e9, 2);
        $this->awaitExit($child);

        self::assertSame(0, $child->exitCode());
        self::assertNull($child->terminationSignal());
    }

    public function testIgnoredTermIsKilledWithoutExtendingOriginalDeadline(): void
    {
        $child = $this->start('pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); echo "ready"; while (true) { usleep(10000); }');
        $this->awaitReady();
        $now = hrtime(true) / 1e9;
        $child->requestStop($now, 0.1);
        self::assertTrue($child->poll($now + 0.05));
        $child->requestStop($now + 0.06, 100);
        self::assertTrue($child->poll($now + 0.11));
        $this->awaitExit($child, $now + 0.11);

        self::assertSame(SIGKILL, $child->terminationSignal());
    }

    public function testNoisyChildrenDoNotBufferOutputInSupervisor(): void
    {
        $memory = memory_get_usage(true);
        $child = $this->start('for ($i = 0; $i < 2048; ++$i) { fwrite(STDOUT, str_repeat("o", 8192)); fwrite(STDERR, str_repeat("e", 8192)); }');
        $this->awaitExit($child);

        self::assertSame(0, $child->exitCode());
        self::assertSame(16 * 1024 * 1024, fstat($this->stdout)['size']);
        self::assertSame(16 * 1024 * 1024, fstat($this->stderr)['size']);
        self::assertLessThanOrEqual($memory + 2 * 1024 * 1024, memory_get_usage(true));
    }

    public function testInvalidWorkingDirectoryFailsBeforeLaunch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkerChildProcess::start([PHP_BINARY, '-r', 'exit(0);'], '/baander.app/nonexistent-worker-directory', $this->stdout, $this->stderr);
    }

    public function testSignalExitIsPreservedAcrossRepeatedPolling(): void
    {
        $child = WorkerChildProcess::start(['/bin/sh', '-c', 'kill -KILL $$'], sys_get_temp_dir(), $this->stdout, $this->stderr);
        $this->children[] = $child;
        $this->awaitExit($child);
        self::assertSame(SIGKILL, $child->terminationSignal());
        self::assertFalse($child->poll(hrtime(true) / 1e9));
        self::assertSame(SIGKILL, $child->terminationSignal());
    }

    public function testChildrenDrainConcurrentlyWithIndependentDeadlines(): void
    {
        $first = $this->start('pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); echo "ready"; while (true) { usleep(10000); }');
        $this->awaitReady();
        ftruncate($this->stdout, 0);
        rewind($this->stdout);
        $second = $this->start('pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); echo "ready"; while (true) { usleep(10000); }');
        $this->awaitReady();
        $now = hrtime(true) / 1e9;
        $first->requestStop($now, 0.1);
        $second->requestStop($now, 100);
        $first->poll($now + 0.2);
        $this->awaitExit($first, $now + 0.2);
        self::assertTrue($second->poll($now + 0.2));
        $second->poll($now + 101);
        $this->awaitExit($second, $now + 101);
        self::assertSame(SIGKILL, $first->terminationSignal());
        self::assertSame(SIGKILL, $second->terminationSignal());
    }

    public function testClockCannotMoveBackwards(): void
    {
        $child = $this->start('usleep(100000);');
        $child->poll(20);
        $this->expectException(\InvalidArgumentException::class);
        $child->poll(19);
    }

    /** @param list<string> $arguments
     *  @param array<string, string>|null $environment
     */
    private function start(string $code, array $arguments = [], ?array $environment = null): WorkerChildProcess
    {
        $child = WorkerChildProcess::start([PHP_BINARY, '-r', $code, '--', ...$arguments], sys_get_temp_dir(), $this->stdout, $this->stderr, $environment);
        $this->children[] = $child;

        return $child;
    }

    private function awaitExit(WorkerChildProcess $child, float $minimumTime = 0): void
    {
        $deadline = microtime(true) + 5;
        while ($child->poll(max($minimumTime, hrtime(true) / 1e9))) {
            if (microtime(true) > $deadline) {
                self::fail('Child did not exit before the test deadline.');
            }
            usleep(1000);
        }
    }

    private function awaitReady(): void
    {
        $deadline = microtime(true) + 5;
        // Read through a separate descriptor: the child shares the writer's OS offset.
        while (file_get_contents(stream_get_meta_data($this->stdout)['uri']) !== 'ready') {
            if (microtime(true) > $deadline) {
                self::fail('Child did not signal readiness before the test deadline.');
            }
            usleep(1000);
        }
    }

}
