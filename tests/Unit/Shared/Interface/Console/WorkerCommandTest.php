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
    public function testExplicitUnitBudgetsReachRunner(): void
    {
        $runner = new RecordingWorkerRunner();
        self::assertSame(7, (new CommandTester(new WorkerCommand($runner)))->execute($this->options()));
        self::assertNotNull($runner->configuration);
        self::assertSame('execution', $runner->configuration->unit);
        self::assertSame('baander.app:workers', $runner->configuration->namespace);
        self::assertSame(str_repeat('a', 32), $runner->configuration->bootId);
        self::assertSame(0, $runner->configuration->scheduledConsoleReservationBytes);
        self::assertSame(4160 * 1024 * 1024, $runner->configuration->memoryLimitBytes);
        self::assertSame(256, $runner->configuration->pidsLimit);
    }

    public function testScheduledConsoleRequiresItsAdditionalReservation(): void
    {
        $runner = new RecordingWorkerRunner();
        $tester = new CommandTester(new WorkerCommand($runner));
        self::assertSame(2, $tester->execute(array_replace($this->options(), ['--scheduled-console-mib' => '320'])));
        self::assertNull($runner->configuration);
        self::assertSame(7, $tester->execute(array_replace($this->options(), ['--memory-mib' => '4480', '--scheduled-console-mib' => '320'])));
        self::assertNotNull($runner->configuration);
        self::assertSame(320 * 1024 * 1024, $runner->configuration->scheduledConsoleReservationBytes);
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidConfigurationNeverStartsSupervisor(string $option, mixed $value): void
    {
        $runner = new RecordingWorkerRunner();
        $options = $this->options();
        if ($value === null) {
            unset($options[$option]);
        } else {
            $options[$option] = $value;
        }
        self::assertSame(2, (new CommandTester(new WorkerCommand($runner)))->execute($options));
        self::assertNull($runner->configuration);
    }

    /** @return iterable<array{string, mixed}> */
    public static function invalidOptions(): iterable
    {
        yield ['--autoscaling-profile', null];
        yield ['--autoscaling-profile', '{}'];
        yield ['--memory-mib', null];
        yield ['--memory-mib', '4160.5'];
        yield ['--memory-mib', '-1'];
        yield ['--memory-mib', '1048577'];
        yield ['--pids-limit', null];
        yield ['--pids-limit', '0'];
        yield ['--pids-limit', '-1'];
        yield ['--pids-limit', '4097'];
        yield ['--scheduled-console-mib', '-1'];
        yield ['--scheduled-console-mib', '320.5'];
        yield ['--scheduled-console-mib', '1048577'];
        yield ['--lock-dir', 'locks'];
        yield ['--unit', null];
        yield ['--unit', 'combined'];
        yield ['--memory-mib', '4159'];
        yield ['--memory-mib', '0'];
        yield ['--pids-limit', '255'];
        yield ['--pids-limit', '256.0'];
        yield ['--scheduled-console-mib', '319'];
        yield ['--scheduled-console-mib', '00'];
        yield ['--ipc-dir', 'relative'];
        yield ['--boot-id', 'bad'];
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

    public function testUnitCannotOverrideTheRegisteredContainerIdentity(): void
    {
        $previous = $_SERVER['BAANDER_WORKER_UNIT'] ?? null;
        $_SERVER['BAANDER_WORKER_UNIT'] = 'control';
        try {
            $runner = new RecordingWorkerRunner();
            self::assertSame(2, (new CommandTester(new WorkerCommand($runner)))->execute($this->options()));
            self::assertNull($runner->configuration);
        } finally {
            if ($previous === null) { unset($_SERVER['BAANDER_WORKER_UNIT']); } else { $_SERVER['BAANDER_WORKER_UNIT'] = $previous; }
        }
    }

    public function testTransportConfigurationFailureNamesTheSettingWithoutCredentials(): void
    {
        $runner = new class implements WorkerSupervisorRunnerInterface {
            public function run(WorkerRuntimeConfiguration $configuration): int
            {
                \App\Shared\Infrastructure\Messaging\WorkerTransportConfiguration::validate([
                    'MESSENGER_TRANSPORT_DSN' => 'redis://operator:private-password@redis.baander.app/messages/baander/fixed',
                ]);
                return 0;
            }
        };
        $tester = new CommandTester(new WorkerCommand($runner));
        self::assertSame(2, $tester->execute($this->options()));
        self::assertStringContainsString('MESSENGER_TRANSPORT_DSN', $tester->getDisplay());
        self::assertStringNotContainsString('private-password', $tester->getDisplay());
        self::assertStringNotContainsString('reconciled', $tester->getDisplay());
    }

    /** @return array<string, string> */
    private function options(): array
    {
        return ['--deployment' => 'baander.app:workers', '--boot-id' => str_repeat('a', 32),
            '--autoscaling-profile' => (new \App\Shared\Application\DTO\WorkerAutoscalingProfile())->toJson(),
            '--unit' => 'execution', '--memory-mib' => '4160', '--pids-limit' => '256',
            '--lock-dir' => '/tmp/baander-worker-locks', '--ipc-dir' => '/run/baander-worker'];
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
