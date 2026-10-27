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
            cwd: '/tmp',
            environment: ['BAANDER_PROCESS_TEST' => 'explicit'],
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
        \Swoole\Timer::after(40, static fn() => $process->cancel(2));
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

    public function testAppendLogPreservesDiagnosticsAcrossCommands(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/process.log';
        file_put_contents($path, "setup\n");
        try {
            $runner = new ProcessRunner();
            foreach (['first', 'second'] as $marker) {
                $result = $runner->run(new ProcessSpec(
                    argv: [PHP_BINARY, '-r', 'echo $argv[1], "\n";', $marker],
                    logPath: $path,
                    appendLog: true,
                ));
                self::assertSame(0, $result->exitCode);
                self::assertSame('', $result->stdout);
                self::assertSame('', $result->stderr);
            }
            self::assertSame("setup\nfirst\nsecond\n", file_get_contents($path));
            self::assertSame(0600, fileperms($path) & 0777);
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

    public function testStandaloneRunnerLoadsNoOptionalProcessExtensions(): void
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

    public function testWorkflowSignalsSurviveNestedWaitsAndBetweenCommandCheckpoints(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/Fixtures/process-runner.php', 'scope-between'], timeout: 3));
        self::assertSame(0, $result->exitCode, $result->stderr);
        $reported = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(15, $reported['between']);
        self::assertSame(15, $reported['latched']);
        self::assertTrue($reported['result']['cancelled']);
        self::assertSame(2, $reported['result']['signal']);
    }

    public function testCleanupCanFinishDespiteRepeatedWorkflowSignals(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/Fixtures/process-runner.php', 'scope-cleanup'], timeout: 3));
        self::assertSame(0, $result->exitCode, $result->stderr);
        $reported = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($reported['cancelled']);
        self::assertSame(15, $reported['latched']);
        self::assertSame(0, $reported['result']['exitCode']);
        self::assertFalse($reported['result']['cancelled']);
        self::assertSame('cleaned', $reported['result']['stdout']);
    }

    public function testClosingSignalScopesRestoresOuterOwnershipAndRemovesFinalHandlers(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/Fixtures/process-runner.php', 'scope-close'], timeout: 3));
        self::assertSame(15, $result->signal, $result->stderr);
        $reported = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([2], $reported['seen']);
        self::assertSame(0, $reported['timers']);
    }

    public function testSeparateLogsPreserveStreamIdentityAndAppendPolicy(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $stdout = $directory . '/stdout';
        $stderr = $directory . '/stderr';
        file_put_contents($stdout, "old stdout\n");
        file_put_contents($stderr, "old stderr\n");
        try {
            $runner = new ProcessRunner();
            foreach ([false, true] as $append) {
                $result = $runner->run(new ProcessSpec(
                    argv: [PHP_BINARY, '-r', 'echo "jsonl\n"; fwrite(STDERR, "diagnostic\n");'],
                    logPath: $stdout,
                    appendLog: $append,
                    stderrLogPath: $stderr,
                ));
                self::assertSame(0, $result->exitCode);
                self::assertSame('', $result->stdout);
                self::assertSame('', $result->stderr);
            }
            self::assertSame("jsonl\njsonl\n", file_get_contents($stdout));
            self::assertSame("diagnostic\ndiagnostic\n", file_get_contents($stderr));
            self::assertSame(0600, fileperms($stdout) & 0777);
            self::assertSame(0600, fileperms($stderr) & 0777);
        } finally {
            unlink($stdout);
            unlink($stderr);
            rmdir($directory);
        }
    }

    public function testStderrCanStreamToALogWhileStdoutRemainsCaptured(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $stderr = $directory . '/stderr';
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: [PHP_BINARY, '-r', 'echo "jsonl\n"; fwrite(STDERR, "diagnostic\n");'],
                stderrLogPath: $stderr,
            ));
            self::assertSame(0, $result->exitCode);
            self::assertSame("jsonl\n", $result->stdout);
            self::assertSame('', $result->stderr);
            self::assertSame("diagnostic\n", file_get_contents($stderr));
        } finally {
            unlink($stderr);
            rmdir($directory);
        }
    }

    public function testAliasedStreamLogsAreRejectedBeforeExistingContentIsTruncated(): void
    {
        $directory = '/tmp/u1-process-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/stdout';
        file_put_contents($path, 'preserved');
        link($path, $directory . '/hardlink');
        symlink($path, $directory . '/symlink');
        try {
            foreach ([$path, $directory . '/hardlink', $directory . '/symlink'] as $stderr) {
                try {
                    new ProcessRunner()->run(new ProcessSpec(argv: $this->child('echo'), logPath: $path, stderrLogPath: $stderr));
                    self::fail('Aliased stream logs must be rejected.');
                } catch (\RuntimeException) {
                    self::assertSame('preserved', file_get_contents($path));
                }
            }
        } finally {
            unlink($directory . '/symlink');
            unlink($directory . '/hardlink');
            unlink($path);
            rmdir($directory);
        }
    }

    public function testTerminationGraceAllowsTimeoutAndCancellationCleanupToFinish(): void
    {
        foreach ([true, false] as $timeout) {
            $runner = new ProcessRunner();
            $process = $runner->start(new ProcessSpec(
                argv: ['bash', '-c', "trap 'sleep 0.4; printf cleaned; exit 0' TERM; while true; do sleep 0.02; done"],
                processGroup: true,
                timeout: $timeout ? 0.1 : null,
                terminationGrace: 0.7,
            ));
            if (!$timeout) {
                \Swoole\Timer::after(100, static fn() => $process->cancel());
            }
            $result = $runner->wait($process);
            self::assertSame($timeout, $result->timedOut);
            self::assertSame(!$timeout, $result->cancelled);
            self::assertSame('cleaned', $result->stdout);
            self::assertSame(0, $result->exitCode);
            self::assertGreaterThan(0.4, $result->duration);
            self::assertLessThan(2, $result->duration);
        }
    }

    public function testSuccessfulGroupsDoNotWaitForTheCancellationGrace(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [PHP_BINARY, '-r', 'exit(0);'],
            processGroup: true,
            terminationGrace: 2,
        ));
        self::assertSame(0, $result->exitCode);
        self::assertLessThan(1.5, $result->duration);
    }

    public function testTerminationGraceRejectsNonFiniteAndNegativeValues(): void
    {
        foreach ([-0.1, INF, NAN] as $grace) {
            try {
                new ProcessSpec(argv: [PHP_BINARY, '-r', 'exit(0);'], terminationGrace: $grace);
                self::fail('An invalid termination grace must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('finite nonnegative', $error->getMessage());
            }
        }
    }

    /** @return list<string> */
    private function child(string $mode): array
    {
        return [PHP_BINARY, __DIR__ . '/Fixtures/process-child.php', $mode];
    }
}
