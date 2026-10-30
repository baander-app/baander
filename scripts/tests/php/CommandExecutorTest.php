<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling;

use Baander\Tooling\Process\{CommandFailure, CompletionPolicy, EngineExecutor, ExecutionContext, ProcessRunner, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class CommandExecutorTest extends TestCase
{
    public function testTimeoutCannotPassWhenTheChildHandlesTerminationAndExitsZero(): void
    {
        foreach ([false, true] as $asynchronous) {
            $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
            $command = new ProcessSpec(
                ['bash', '-c', 'trap "printf cleaned; exit 0" TERM; while true; do sleep 0.02; done'],
                timeout: 0.3,
                processGroup: true,
            );
            try {
                if ($asynchronous) {
                    $executor->wait($executor->start($command), new CompletionPolicy(null));
                } else {
                    $executor->run($command, new CompletionPolicy(null));
                }
                self::fail('Timeout was accepted as a successful command.');
            } catch (CommandFailure $failure) {
                self::assertSame(124, $failure->getCode());
                self::assertNotNull($failure->result);
                self::assertTrue($failure->result->timedOut);
                self::assertSame(0, $failure->result->exitCode);
                self::assertSame('cleaned', $failure->result->stdout);
            } finally {
                $executor->close();
            }
        }
    }

    public function testGracefulCancellationRetainsTheRequestedInterruptSignal(): void
    {
        $ready = sys_get_temp_dir() . '/baander.app-executor-' . bin2hex(random_bytes(8));
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        try {
            $process = $executor->start(new ProcessSpec(
                ['bash', '-c', 'trap "exit 0" INT; touch "$1"; while true; do sleep 0.02; done', 'fixture', $ready],
                processGroup: true,
            ));
            while (!is_file($ready)) {
                $executor->checkpoint();
                $executor->pump();
                usleep(5_000);
            }
            $process->cancel(2);
            try {
                $executor->wait($process, new CompletionPolicy(null));
                self::fail('Cancellation was accepted.');
            } catch (CommandFailure $failure) {
                self::assertSame(130, $failure->getCode());
                self::assertNotNull($failure->result);
                self::assertSame(0, $failure->result->exitCode);
                self::assertSame(2, $failure->result->requestedSignal);
            }
        } finally {
            $executor->close();
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }

    public function testExpectedNonzeroExitAndExplicitStopHaveDistinctOutcomes(): void
    {
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        try {
            $command = new ProcessSpec(['bash', '-c', 'printf diagnostic >&2; exit 7']);
            self::assertSame(7, $executor->run($command, new CompletionPolicy([7]))->exitCode);
            try {
                $executor->run($command);
                self::fail('Unexpected nonzero exit was accepted.');
            } catch (CommandFailure $failure) {
                self::assertSame(7, $failure->getCode());
                self::assertStringContainsString('diagnostic', $failure->getMessage());
            }
            $process = $executor->start(new ProcessSpec(['bash', '-c', 'sleep 10'], processGroup: true));
            $observed = $executor->stop($process);
            self::assertTrue($observed->cancelled);
            self::assertSame(15, $observed->requestedSignal);
        } finally {
            $executor->close();
        }
    }

    public function testCommandEnvironmentIsScopedAndCanExplicitlyReplaceTheSnapshot(): void
    {
        $before = getenv();
        $executor = new EngineExecutor(new ExecutionContext(['BAANDER_EXECUTOR_VALUE' => 'base']));
        try {
            $command = [PHP_BINARY, '-r', 'echo getenv("BAANDER_EXECUTOR_VALUE") ?: "absent";'];
            self::assertSame('overlay', $executor->run(new ProcessSpec($command, environment: ['BAANDER_EXECUTOR_VALUE' => 'overlay']))->stdout);
            self::assertSame('base', $executor->run(new ProcessSpec($command))->stdout);
            self::assertSame('absent', $executor->run(new ProcessSpec($command, replaceEnvironment: true))->stdout);
            self::assertSame($before, getenv());
        } finally {
            $executor->close();
        }
    }

    public function testCompletedHandlesAndTheirCapturedOutputAreReleased(): void
    {
        $executor = new EngineExecutor(ExecutionContext::bounded(10, getenv()));
        try {
            for ($index = 0; $index < 6; $index++) {
                $process = $executor->start(new ProcessSpec([PHP_BINARY, '-r', 'echo str_repeat("a", 1048576);']));
                $reference = \WeakReference::create($process);
                self::assertSame(1_048_576, strlen($executor->wait($process)->stdout));
                unset($process);
                self::assertNull($reference->get(), 'Completed output is retained by the executor.');
            }
        } finally {
            $executor->close();
        }
    }

    public function testCleanupGetsItsOwnFiniteBudgetAfterTheOrdinaryDeadline(): void
    {
        $ordinary = new EngineExecutor(new ExecutionContext(getenv(), deadline: hrtime(true) / 1_000_000_000 - 1));
        try {
            $ordinary->run(new ProcessSpec(['bash', '-c', 'exit 0']));
            self::fail('Expired context spawned a successful command.');
        } catch (CommandFailure $failure) {
            self::assertSame(124, $failure->getCode());
        } finally {
            $ordinary->close();
        }
        $cleanup = new EngineExecutor(ExecutionContext::bounded(2, getenv(), ignoreCancellation: true));
        try {
            self::assertSame('removed', $cleanup->run(new ProcessSpec(['bash', '-c', 'printf removed']))->stdout);
        } finally {
            $cleanup->close();
        }
    }

    public function testAsynchronousFailureKeepsItsLogReferenceAfterPumping(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-executor-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $log = $directory . '/command.log';
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        try {
            $process = $executor->start(new ProcessSpec(['bash', '-c', 'printf diagnostic >&2; exit 9'], logPath: $log));
            while ($process->result() === null) {
                $executor->checkpoint();
                $executor->pump();
            }
            try {
                $executor->wait($process);
                self::fail('Failure was accepted.');
            } catch (CommandFailure $failure) {
                self::assertSame(9, $failure->getCode());
                self::assertStringContainsString($log, $failure->getMessage());
            }
        } finally {
            $executor->close();
            if (is_file($log)) {
                unlink($log);
            }
            rmdir($directory);
        }
    }

    public function testRetainedFailureDoesNotCrashTheProcessDuringShutdown(): void
    {
        $source = <<<'PHP'
            require $argv[1];
            $GLOBALS['executor'] = new Baander\Tooling\Process\EngineExecutor(new Baander\Tooling\Process\ExecutionContext(getenv()));
            try {
                $GLOBALS['executor']->run(new Baander\Tooling\Process\ProcessSpec(['bash', '-c', 'sleep 2'], timeout: 0.05, processGroup: true));
            } catch (Baander\Tooling\Process\CommandFailure $failure) {
                echo $failure->getCode(), "\n";
            }
            PHP;
        $result = new ProcessRunner()->run(new ProcessSpec(
            [PHP_BINARY, '-r', $source, dirname(__DIR__, 2) . '/bootstrap.php'],
            environment: getenv(),
            timeout: 5,
        ));
        self::assertSame("124\n", $result->stdout);
        self::assertSame(0, $result->exitCode, $result->stderr);
    }
}
