<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use App\Shared\Application\Port\WorkerSupervisorRunnerInterface;
use App\Shared\Interface\Console\WorkerCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerCommandTest extends TestCase
{
    public function testExplicitBudgetsAndIdentityReachRunnerAndExitStatusIsPreserved(): void
    {
        $runner = new RecordingWorkerRunner();
        $tester = new CommandTester(new WorkerCommand($runner));
        self::assertSame(7, $tester->execute($this->options()));
        self::assertNotNull($runner->configuration);
        self::assertSame('baander.app:workers', $runner->configuration->namespace);
        self::assertSame(str_repeat('a', 32), $runner->configuration->bootId);
        self::assertSame(1344 * 1024 * 1024, $runner->configuration->memoryLimitBytes);
        self::assertSame(384 * 1024 * 1024, $runner->configuration->consumerReservationBytes);
        self::assertSame(0, $runner->configuration->scheduledConsoleReservationBytes);
        self::assertSame(320 * 1024 * 1024, $runner->configuration->schedulerReservationBytes);
    }

    public function testExplicitConsoleReservationCanFitWithoutIncreasingTotalCeiling(): void
    {
        $runner = new RecordingWorkerRunner();
        $tester = new CommandTester(new WorkerCommand($runner));
        $options = array_replace($this->options(), ['--management-mib' => '128', '--consumer-mib' => '320', '--relay-mib' => '320', '--scheduled-console-mib' => '192']);
        self::assertSame(7, $tester->execute($options));
        self::assertNotNull($runner->configuration);
        self::assertSame(1344 * 1024 * 1024, $runner->configuration->memoryLimitBytes);
        self::assertSame(192 * 1024 * 1024, $runner->configuration->scheduledConsoleReservationBytes);
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidConfigurationNeverStartsSupervisor(string $option, mixed $value): void
    {
        $runner = new RecordingWorkerRunner();
        $tester = new CommandTester(new WorkerCommand($runner));
        $options = $this->options();
        if ($value === null) {
            unset($options[$option]);
        } else {
            $options[$option] = $value;
        }
        self::assertSame(2, $tester->execute($options));
        self::assertNull($runner->configuration);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidOptions(): iterable
    {
        yield 'missing scheduler budget' => ['--scheduler-mib', null];
        yield 'scheduler below minimum' => ['--scheduler-mib', '319'];
        yield 'legacy total excludes scheduler' => ['--memory-mib', '1024'];
        yield 'missing budget' => ['--memory-mib', null];
        yield 'fractional budget' => ['--memory-mib', '1024.5'];
        yield 'negative budget' => ['--memory-mib', '-1'];
        yield 'too large' => ['--memory-mib', '1048577'];
        yield 'overcommitted' => ['--memory-mib', '768'];
        yield 'no management reserve' => ['--management-mib', '64'];
        yield 'no native reserve' => ['--consumer-mib', '256'];
        yield 'console without available capacity' => ['--scheduled-console-mib', '192'];
        yield 'console below minimum' => ['--scheduled-console-mib', '191'];
        yield 'negative console reservation' => ['--scheduled-console-mib', '-1'];
        yield 'fractional console reservation' => ['--scheduled-console-mib', '192.5'];
        yield 'noncanonical zero console reservation' => ['--scheduled-console-mib', '00'];
        yield 'oversized console reservation' => ['--scheduled-console-mib', '1048577'];
        yield 'bad boot' => ['--boot-id', 'baander.app'];
        yield 'relative lock directory' => ['--lock-dir', 'locks'];
    }

    public function testEnvironmentIdentityDefaultsAndErrorsDoNotExposeSecrets(): void
    {
        $oldNamespace = $_SERVER['BAANDER_WORKER_NAMESPACE'] ?? null;
        $oldBoot = $_SERVER['BAANDER_WORKER_BOOT_ID'] ?? null;
        $_SERVER['BAANDER_WORKER_NAMESPACE'] = 'baander.app:environment';
        $_SERVER['BAANDER_WORKER_BOOT_ID'] = str_repeat('b', 32);
        try {
            $runner = new RecordingWorkerRunner();
            $runner->fail = true;
            $tester = new CommandTester(new WorkerCommand($runner));
            $options = $this->options();
            unset($options['--deployment'], $options['--boot-id']);
            self::assertSame(1, $tester->execute($options));
            self::assertNotNull($runner->configuration);
            self::assertSame('baander.app:environment', $runner->configuration->namespace);
            self::assertStringNotContainsString('private-database-password', $tester->getDisplay());
            $otherRunner = new RecordingWorkerRunner();
            self::assertSame(2, (new CommandTester(new WorkerCommand($otherRunner)))->execute($this->options()));
            self::assertNull($otherRunner->configuration, 'CLI options must not override the registered container identity.');
        } finally {
            if ($oldNamespace === null) { unset($_SERVER['BAANDER_WORKER_NAMESPACE']); } else { $_SERVER['BAANDER_WORKER_NAMESPACE'] = $oldNamespace; }
            if ($oldBoot === null) { unset($_SERVER['BAANDER_WORKER_BOOT_ID']); } else { $_SERVER['BAANDER_WORKER_BOOT_ID'] = $oldBoot; }
        }
    }

    /** @return array<string, string> */
    private function options(): array
    {
        return ['--deployment' => 'baander.app:workers', '--boot-id' => str_repeat('a', 32),
            '--memory-mib' => '1344', '--management-mib' => '256', '--consumer-mib' => '384', '--relay-mib' => '384', '--scheduler-mib' => '320',
            '--lock-dir' => '/tmp/baander-worker-locks'];
    }
}

final class RecordingWorkerRunner implements WorkerSupervisorRunnerInterface
{
    public ?WorkerRuntimeConfiguration $configuration = null;
    public bool $fail = false;

    public function run(WorkerRuntimeConfiguration $configuration): int
    {
        $this->configuration = $configuration;
        if ($this->fail) {
            throw new \RuntimeException('private-database-password');
        }
        return 7;
    }
}
