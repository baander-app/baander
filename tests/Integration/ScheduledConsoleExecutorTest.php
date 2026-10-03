<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use App\Scheduler\Infrastructure\Process\BoundedScheduledConsoleExecutor;
use App\Scheduler\Infrastructure\Messenger\StopWorkerOnUnknownScheduledConsole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/** Real isolated PHP children; reaping assertions do not certify descendant containment. */
final class ScheduledConsoleExecutorTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/baander-scheduler-console-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->project, 0700));
        self::assertTrue(mkdir($this->project . '/bin', 0700));
        self::assertTrue(copy(dirname(__DIR__) . '/Fixtures/Scheduler/console.php', $this->project . '/bin/console'));
    }

    public function testShellMetacharactersAreLiteralAndExplicitHeapAndFlagsReachChild(): void
    {
        $marker = $this->project . '/shell-executed';
        $text = 'scheduler@baander.app; touch ' . $marker . ' $(touch ' . $marker . ') `touch ' . $marker . '`';
        $output = (new BoundedScheduledConsoleExecutor($this->project, memoryLimitMiB: 64, reservationBytes: 128 * 1024 * 1024))->execute('app:fixture-echo', [
            '--text' => $text, '--enabled' => true, '--omitted' => false,
            '--integer' => 7, '--fraction' => 1.0, '--negative-zero' => -0.0, '--empty' => '',
            0 => 'positional value', 1 => '--option-looking-positional',
        ]);
        $decoded = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('64M', $decoded['memoryLimit']);
        $arguments = $decoded['arguments'];
        self::assertContains('--no-interaction', $arguments);
        self::assertContains('--no-ansi', $arguments);
        self::assertContains('--enabled', $arguments);
        self::assertNotContains('--omitted', $arguments);
        self::assertContains('--text=' . $text, $arguments);
        self::assertContains('--integer=7', $arguments);
        self::assertContains('--fraction=1.0', $arguments);
        self::assertContains('--negative-zero=-0.0', $arguments);
        self::assertContains('--empty=', $arguments);
        $separator = array_search('--', $arguments, true);
        self::assertIsInt($separator);
        self::assertSame(['positional value', '--option-looking-positional'], array_slice($arguments, $separator + 1));
        self::assertFileDoesNotExist($marker);
    }

    public function testBothOutputPipesAreDrainedWithoutDeadlock(): void
    {
        $output = (new BoundedScheduledConsoleExecutor($this->project, timeoutSeconds: 2.0, outputLimitBytes: 262144, reservationBytes: 192 * 1024 * 1024))->execute('app:fixture-flood', []);
        self::assertSame(24 * 4096, substr_count($output, 'O'));
        self::assertSame(0, substr_count($output, 'E'), 'Successful output contains stdout, while stderr is drained separately.');
        self::assertLessThanOrEqual(262144, strlen($output));
    }

    public function testNonzeroExitIsKnownFailureAndDirectChildIsReaped(): void
    {
        $pidFile = $this->project . '/child.pid';
        try {
            (new BoundedScheduledConsoleExecutor($this->project, reservationBytes: 192 * 1024 * 1024))->execute('app:fixture-exit', ['--pid-file' => $pidFile]);
            self::fail('Expected known nonzero console failure.');
        } catch (\RuntimeException $error) {
            self::assertNotInstanceOf(ScheduledConsoleCompletionUnknown::class, $error);
        }
        $this->assertDirectChildStopped($pidFile);
    }

    /** @return iterable<string, array{string}> */
    public static function uncertainCompletions(): iterable
    {
        yield 'timeout with TERM ignored' => ['app:fixture-timeout'];
        yield 'output overflow with TERM ignored' => ['app:fixture-output-overflow'];
        yield 'unexpected direct-child signal' => ['app:fixture-signal'];
    }

    #[DataProvider('uncertainCompletions')]
    public function testUnknownCompletionStopsAndReapsDirectChild(string $command): void
    {
        self::assertTrue(function_exists('pcntl_signal'), 'Canonical runtime must exercise ignored TERM followed by KILL.');
        $pidFile = $this->project . '/child.pid';
        $started = hrtime(true) / 1e9;
        try {
            (new BoundedScheduledConsoleExecutor($this->project, timeoutSeconds: 0.5, outputLimitBytes: 4096, terminationGraceSeconds: 0.1, reservationBytes: 192 * 1024 * 1024))
                ->execute($command, ['--pid-file' => $pidFile]);
            self::fail('Expected uncertain console completion.');
        } catch (ScheduledConsoleCompletionUnknown $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertLessThan(5.0, hrtime(true) / 1e9 - $started, 'Small configured deadline/grace must not wait for the hanging fixture.');
        $this->assertDirectChildStopped($pidFile);
    }

    public function testMalformedUtf8IsRejectedAfterTheChildWasReaped(): void
    {
        $pidFile = $this->project . '/child.pid';
        try {
            (new BoundedScheduledConsoleExecutor($this->project, reservationBytes: 192 * 1024 * 1024))->execute('app:fixture-invalid-utf8', ['--pid-file' => $pidFile]);
            self::fail('Expected invalid text failure.');
        } catch (\RuntimeException $error) {
            self::assertNotInstanceOf(ScheduledConsoleCompletionUnknown::class, $error);
        }
        $this->assertDirectChildStopped($pidFile);
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'invalid command' => ['app:fixture-echo;touch', []];
        yield 'NUL command' => ["app:fixture-echo\0", []];
        yield 'invalid option name' => ['app:fixture-echo', ['--bad name' => 'value']];
        yield 'NUL value' => ['app:fixture-echo', ['--text' => "invalid\0text"]];
        yield 'nonfinite float' => ['app:fixture-echo', ['--text' => INF]];
        yield 'nested data' => ['app:fixture-echo', ['--text' => ['nested']]];
        yield 'null value' => ['app:fixture-echo', ['--text' => null]];
        yield 'oversized argv' => ['app:fixture-echo', ['--text' => str_repeat('x', 16385)]];
    }

    /** @param array<array-key, mixed> $parameters */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputDoesNotStartAnyChild(string $command, array $parameters): void
    {
        $pidFile = $this->project . '/child.pid';
        try {
            (new BoundedScheduledConsoleExecutor($this->project, reservationBytes: 192 * 1024 * 1024))->execute($command, ['--pid-file' => $pidFile] + $parameters);
            self::fail('Expected input validation before spawning.');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertFileDoesNotExist($pidFile);
    }

    public function testMissingReservationRejectsBeforeSpawn(): void
    {
        $pidFile = $this->project . '/unadmitted.pid';
        try {
            (new BoundedScheduledConsoleExecutor($this->project))->execute('app:fixture-echo', ['--pid-file' => $pidFile]);
            self::fail('No child is admitted by default.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('disabled', $error->getMessage());
        }
        self::assertFileDoesNotExist($pidFile);
    }

    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function environmentBudgets(): iterable
    {
        yield 'absent' => [null, 'consumer', false];
        yield 'disabled' => ['0', 'consumer', false];
        yield 'empty' => ['', 'consumer', false];
        yield 'negative' => ['-1', 'consumer', false];
        yield 'fraction' => ['201326592.0', 'consumer', false];
        yield 'leading zero' => ['0201326592', 'consumer', false];
        yield 'too small' => [(string) (192 * 1024 * 1024 - 1), 'consumer', false];
        yield 'no native headroom' => [(string) (128 * 1024 * 1024), 'consumer', false];
        yield 'integer overflow' => ['9999999999999999999999999', 'consumer', false];
        yield 'over ceiling' => [(string) (1024 * 1024 * 1024 * 1024 + 1), 'consumer', false];
        yield 'wrong role' => [(string) (192 * 1024 * 1024), 'relay', false];
        yield 'no role' => [(string) (192 * 1024 * 1024), null, false];
        yield 'explicit minimum' => [(string) (192 * 1024 * 1024), 'consumer', true];
    }

    #[DataProvider('environmentBudgets')]
    public function testEnvironmentGrantIsBoundedRoleSpecificAndNotInheritedByChild(?string $budget, ?string $role, bool $allowed): void
    {
        $variables = ['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES' => $budget, 'BAANDER_WORKER_ID' => $role,
            'SYMFONY_DOTENV_VARS' => 'BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'];
        $previous = [];
        foreach ($variables as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($value === null ? $key : $key . '=' . $value);
        }
        $pidFile = $this->project . '/admitted.pid';
        try {
            try {
                $output = BoundedScheduledConsoleExecutor::fromWorkerEnvironment($this->project)->execute('app:fixture-budget', ['--pid-file' => $pidFile]);
                self::assertTrue($allowed, 'Invalid or disabled budget must not create a process.');
                self::assertSame(['budget' => '0', 'dotenv' => false], json_decode($output, true, 32, JSON_THROW_ON_ERROR));
                $this->assertDirectChildStopped($pidFile);
                self::assertSame($budget, getenv('BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'), 'Grant remains available to the owning serial consumer.');
            } catch (\InvalidArgumentException|\RuntimeException $error) {
                if ($allowed) {
                    throw $error;
                }
                self::assertFileDoesNotExist($pidFile);
            }
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    public function testReservationMustCoverConfiguredHeapAsWellAsNativeHeadroom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BoundedScheduledConsoleExecutor($this->project, memoryLimitMiB: 256, reservationBytes: 192 * 1024 * 1024);
    }

    public function testConfiguredKernelResolvesActualExecutorPort(): void
    {
        $kernel = new Kernel('test', false);
        try {
            $kernel->boot();
            self::assertInstanceOf(BoundedScheduledConsoleExecutor::class, $kernel->getContainer()->get('test.service_container')->get(ScheduledConsoleExecutorInterface::class));
            $listeners = $kernel->getContainer()->get('test.service_container')->get('event_dispatcher')->getListeners(WorkerMessageFailedEvent::class);
            $registered = false;
            foreach ($listeners as $listener) {
                if (is_array($listener) && ($listener[0] ?? null) instanceof StopWorkerOnUnknownScheduledConsole) {
                    $registered = true;
                }
            }
            self::assertTrue($registered, 'Configured Messenger dispatcher must stop this worker on unknown console completion.');
        } finally {
            $kernel->shutdown();
        }
    }

    private function assertDirectChildStopped(string $pidFile): void
    {
        self::assertFileExists($pidFile);
        $pid = (int) file_get_contents($pidFile);
        self::assertGreaterThan(1, $pid);
        self::assertTrue(function_exists('posix_kill'));
        self::assertFalse(posix_kill($pid, 0), 'The exact direct child must be stopped and reaped.');
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            foreach (glob($this->project . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            unlink($this->project . '/bin/console');
            rmdir($this->project . '/bin');
            rmdir($this->project);
        }
    }
}
