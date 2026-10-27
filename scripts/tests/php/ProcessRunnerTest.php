<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testLiteralArgumentsEnvironmentWorkingDirectoryAndExitArePreserved(): void
    {
        $arguments = ['', 'literal space', '"quote"', '$dollar;command', "new\nline"];
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [PHP_BINARY, __DIR__ . '/Fixtures/process-child.php', 'echo', ...$arguments],
            cwd: '/tmp', environment: ['BAANDER_PROCESS_TEST' => 'explicit'],
        ));
        self::assertSame(7, $result->exitCode);
        self::assertNull($result->signal);
        self::assertSame(['argv' => $arguments, 'cwd' => '/tmp', 'env' => 'explicit'], json_decode($result->stdout, true));
        self::assertSame('diagnostic', $result->stderr);
    }

    public function testConcurrentStreamsAndPartialInputMakeProgressAcrossMultipleChildren(): void
    {
        $runner = new ProcessRunner();
        $input = str_repeat('input', 300_000);
        $first = $runner->start(new ProcessSpec(argv: $this->child('streams'), stdin: $input, timeout: 5));
        $second = $runner->start(new ProcessSpec(argv: $this->child('streams'), stdin: $input, timeout: 5));
        foreach ([$runner->wait($first), $runner->wait($second)] as $result) {
            self::assertSame(0, $result->exitCode);
            self::assertFalse($result->timedOut);
            self::assertSame(str_repeat('o', 2_097_152) . hash('sha256', $input), $result->stdout);
            self::assertSame(str_repeat('e', 2_097_152), $result->stderr);
        }
    }

    public function testSilentChildDeadlineIsBounded(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child('sleep'), timeout: 0.08));
        self::assertTrue($result->timedOut);
        self::assertSame(15, $result->signal);
        self::assertLessThan(2, $result->duration);
    }

    public function testCancellationPumpsWhileChildIsSilent(): void
    {
        $runner = new ProcessRunner();
        $process = $runner->start(new ProcessSpec(argv: $this->child('sleep')));
        \Swoole\Timer::after(40, static fn () => $process->cancel(2));
        $result = $runner->wait($process);
        self::assertTrue($result->cancelled);
        self::assertSame(2, $result->signal);
        self::assertLessThan(2, $result->duration);
    }

    public function testInheritedPipesAreBoundedAndOwnedDescendantsAreStopped(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child('descendant'), processGroup: true, timeout: 3));
        self::assertSame(0, $result->exitCode);
        self::assertFalse($result->timedOut);
        self::assertLessThan(2, $result->duration);
        $stat = @file_get_contents('/proc/' . trim($result->stdout) . '/stat');
        self::assertTrue($stat === false || preg_match('/\) Z /', $stat) === 1, 'The descendant must have exited.');
    }

    public function testOutputStreamsIntoLog(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/process.log';
        file_put_contents($path, 'old log');
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child('echo'), logPath: $path));
            self::assertStringContainsString('diagnostic', file_get_contents($path));
            self::assertStringNotContainsString('old log', file_get_contents($path));
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertStringContainsString('argv', file_get_contents($path));
            self::assertSame('', $result->stdout);
            self::assertSame('', $result->stderr);
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    public function testParentSignalsAreForwardedForSilentChildren(): void
    {
        foreach ([2, 15] as $signal) {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: [PHP_BINARY, __DIR__ . '/Fixtures/process-runner.php', 'signal', (string) $signal],
                timeout: 3,
            ));
            self::assertSame(0, $result->exitCode, $result->stderr);
            $reported = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($reported['result']['cancelled']);
            self::assertSame($signal, $reported['result']['signal']);
        }
    }

    public function testIgnoringTerminationEscalatesWithinTheOwnedGroup(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: ['bash', '-c', 'trap "" TERM; printf ready; while true; do sleep 10; done'],
            processGroup: true,
            timeout: 0.15,
        ));
        self::assertSame('ready', $result->stdout);
        self::assertTrue($result->timedOut);
        self::assertSame(9, $result->signal);
        self::assertSame(137, $result->exitCode);
        self::assertLessThan(2, $result->duration);
    }

    public function testInheritedOutputWithoutGroupOwnershipHasABoundedDrain(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child('descendant')));
        try {
            self::assertSame(0, $result->exitCode);
            self::assertLessThan(2, $result->duration);
        } finally {
            \Swoole\Process::kill((int) trim($result->stdout), 15);
        }
    }

    public function testPassthroughInheritsTheCallersDescriptors(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [PHP_BINARY, __DIR__ . '/Fixtures/process-runner.php', 'passthrough'],
            stdin: 'interactive input',
            timeout: 3,
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringStartsWith('interactive input', $result->stdout);
        self::assertSame('terminal', $result->stderr);
        $reported = json_decode(substr($result->stdout, strlen('interactive input')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $reported['result']['stdout']);
        self::assertSame('', $reported['result']['stderr']);
    }

    public function testStandaloneRunnerLoadsNoOptionalProcessExtensionsOrVendor(): void
    {
        $runner = new ProcessRunner();
        $baseline = $runner->run(new ProcessSpec(argv: [PHP_BINARY, '-n', '-r', 'echo json_encode(get_loaded_extensions());']));
        $builtIn = json_decode($baseline->stdout, true, flags: JSON_THROW_ON_ERROR);
        $runtime = [PHP_BINARY, '-n'];
        foreach (['curl', 'openssl', 'sockets', 'swoole'] as $extension) {
            if (extension_loaded($extension) && !in_array($extension, $builtIn, true)) {
                $runtime[] = '-d';
                $runtime[] = 'extension=' . $extension;
            }
        }
        $result = $runner->run(new ProcessSpec(
            argv: [...$runtime, __DIR__ . '/Fixtures/process-runner.php', 'standalone'],
            timeout: 3,
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        $reported = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(in_array('pcntl', $builtIn, true), $reported['pcntl']);
        self::assertSame(in_array('posix', $builtIn, true), $reported['posix']);
        self::assertSame('standalone', $reported['result']['stdout']);
        self::assertSame(0, $reported['result']['exitCode']);
    }

    public function testCallerTimersRemainOwnedByTheCaller(): void
    {
        $timer = \Swoole\Timer::after(3000, static function (): void {});
        try {
            $runner = new ProcessRunner();
            $first = $runner->run(new ProcessSpec(argv: [PHP_BINARY, '-r', 'exit(0);']));
            self::assertSame(0, $first->exitCode);
            self::assertTrue(\Swoole\Timer::exists($timer));
        } finally {
            \Swoole\Timer::clear($timer);
            \Swoole\Event::wait();
        }
    }

    public function testSymlinkLogTargetsAreRejectedWithoutChangingTheirContent(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $target = $directory . '/target';
        file_put_contents($target, 'preserved');
        symlink($target, $directory . '/linked');
        try {
            try {
                new ProcessRunner()->run(new ProcessSpec(argv: $this->child('echo'), logPath: $directory . '/linked'));
                self::fail('A symlink process log must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('symlink', $error->getMessage());
            }
            self::assertSame('preserved', file_get_contents($target));
        } finally {
            unlink($directory . '/linked');
            unlink($target);
            rmdir($directory);
        }
    }

    public function testOneRunnerSupportsSequentialRunsAndRepeatedWaits(): void
    {
        $runner = new ProcessRunner();
        foreach ([0, 3, 17] as $exitCode) {
            $process = $runner->start(new ProcessSpec(argv: [PHP_BINARY, '-r', 'exit(' . $exitCode . ');']));
            $result = $runner->wait($process);
            self::assertSame($exitCode, $result->exitCode);
            self::assertSame($result, $runner->wait($process));
        }
    }

    public function testLargeLogsStreamWithoutRetainingOutputInMemory(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/process.log';
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: [PHP_BINARY, '-d', 'memory_limit=24M', __DIR__ . '/Fixtures/process-runner.php', 'log', $path],
                timeout: 10,
            ));
            self::assertSame(0, $result->exitCode, $result->stderr);
            $reported = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $reported['result']['exitCode']);
            self::assertSame('', $reported['result']['stdout']);
            self::assertSame('', $reported['result']['stderr']);
            self::assertSame(33_554_432, filesize($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** @return list<string> */
    private function child(string $mode): array
    {
        return [PHP_BINARY, __DIR__ . '/Fixtures/process-child.php', $mode];
    }
}
