<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\{ImageProfile, WorkerConfiguration, WorkerLifecycle, WorkerStateStore};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\TestCase;

final class WorkerLifecycleTest extends TestCase
{
    private string $directory;
    private WorkerStateStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-worker-lifecycle-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $this->directory . '/docker');
        chmod($this->directory . '/docker', 0700);
        AtomicFile::writeJson($this->directory . '/daemon.json', ['daemonId' => 'daemon']);
        $this->store = new class ($this->directory . '/workers') implements WorkerStateStore {
            /** @var array<string,mixed>|null */
            private ?array $record = null;
            public function __construct(private readonly string $root) {}
            public function directory(): string
            {
                return $this->root;
            }
            public function load(): ?array
            {
                return $this->record;
            }
            public function save(array $record): void
            {
                $this->record = $record;
            }
        };
    }

    protected function tearDown(): void
    {
        Collector::remove($this->directory);
    }

    /** @param array<string,mixed> $overrides */
    private function configuration(array $overrides = []): WorkerConfiguration
    {
        $profile = ImageProfile::fromArray([
            'version' => 1,
            'deployment' => ['identifier' => 'household', 'webWorkers' => 2, 'doctrinePoolSlots' => 4],
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256],
            'budgets' => ['controlMemoryMiB' => 2304, 'executionMemoryMiB' => 4480, 'controlPidsLimit' => 129, 'executionPidsLimit' => 256, 'scheduledConsoleMiB' => 320],
        ]);
        return new WorkerConfiguration(...array_replace([
            'namespace' => 'household-proof',
            'target' => DevelopmentCommandExecutor::target($this->directory . '/docker'),
            'network' => 'household-network',
            'workerImage' => 'sha256:' . str_repeat('a', 64),
            'operatorImage' => 'sha256:' . str_repeat('b', 64),
            'layout' => ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']),
            'runtimeEnvironment' => ['APP_ENV' => 'prod', 'DATABASE_URL' => 'postgresql://baander@postgres/baander'],
            'sourceMountPolicy' => 'production',
            'mediaMounts' => [
                ['volume' => 'household-media', 'target' => '/storage/media', 'role' => 'source', 'readOnly' => true],
                ['volume' => 'household-derived', 'target' => '/srv/baander/storage', 'role' => 'derived', 'readOnly' => false],
            ],
            'resourceLabels' => ['app.baander.household.owner' => 'household-proof'],
            'profile' => $profile,
            'operatorMemoryMiB' => 512,
        ], $overrides));
    }

    public function testProductionPreparationUsesImageBudgetAndPersistsIntentBeforeCreatingVolumes(): void
    {
        $runner = new WorkerCommandExecutor(function (array $arguments): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon","NCPU":4,"MemTotal":7650410496}';
            }
            if (in_array('volume', $arguments, true) && in_array('create', $arguments, true)) {
                self::assertSame('preparing', $this->store->load()['phase']);
                return $arguments[array_key_last($arguments)];
            }
            if (in_array('worker-controller', $arguments, true)) {
                return '{"success":true,"action":"status","result":{"state":"ready"}}';
            }
            return '';
        });
        $record = null;
        $configuration = $this->configuration(['controlCpuSet' => [2], 'executionCpuSet' => [3]]);
        $lifecycle = new WorkerLifecycle($this->store, $runner, getenv());
        $lifecycle->prepare($record, $configuration);
        self::assertSame('prepared', $record['phase']);
        self::assertSame($record, $this->store->load());
        self::assertSame($configuration->target, $record['target']);
        self::assertSame('household-network', $record['network']);
        self::assertSame(512, $record['operator_memory_mib']);
        $manifest = AtomicFile::readJson($record['directory'] . '/manifest.json');
        self::assertSame('production', $manifest['sourceMountPolicy']);
        self::assertSame(2304, $manifest['controlMemoryMiB']);
        self::assertSame(4480, $manifest['executionMemoryMiB']);
        self::assertSame([2], $manifest['controlCpuSet']);
        self::assertSame([3], $manifest['executionCpuSet']);
        self::assertSame([], WorkerLifecycle::sourceMountArguments($manifest));
        self::assertEquals($configuration->mediaMounts, $manifest['mediaMounts']);
        self::assertSame(['state' => 'ready'], $lifecycle->invoke($record, 'status'));
        $operator = array_find($runner->calls, static fn(array $call): bool => in_array('worker-controller', $call['arguments'], true));
        self::assertSame('512m', $operator['arguments'][array_search('--memory', $operator['arguments'], true) + 1]);
        self::assertSame('512m', $operator['arguments'][array_search('--memory-swap', $operator['arguments'], true) + 1]);
        foreach ($runner->calls as $call) {
            if (($call['arguments'][3] ?? null) === 'create') {
                self::assertContains('--memory', $call['arguments']);
                self::assertSame('512m', $call['arguments'][array_search('--memory', $call['arguments'], true) + 1]);
                self::assertSame('512m', $call['arguments'][array_search('--memory-swap', $call['arguments'], true) + 1]);
            }
        }
    }

    public function testKnownOperatorReservationCountsTowardDaemonMemoryAdmission(): void
    {
        $runner = new WorkerCommandExecutor(static fn(): string => '{"ID":"daemon","NCPU":2,"MemTotal":7340032000}');
        $record = null;
        $this->expectExceptionMessage('7296 MiB');
        try {
            new WorkerLifecycle($this->store, $runner, getenv())->prepare($record, $this->configuration());
        } finally {
            self::assertNull($record);
            self::assertNull($this->store->load());
        }
    }

    public function testProductionMountPolicyRefusesHostSources(): void
    {
        $this->expectExceptionMessage('named volumes');
        $this->configuration(['mediaMounts' => [['source' => '/media/baander', 'target' => '/media', 'role' => 'source', 'readOnly' => true]]]);
    }

    public function testOverlappingCpuSetsAreRefused(): void
    {
        $this->expectExceptionMessage('must not overlap');
        $this->configuration(['controlCpuSet' => [0, 1], 'executionCpuSet' => [1]]);
    }

    public function testUncertainCreationIsSavedWithoutDevelopmentInstanceState(): void
    {
        $lifecycle = new class ($this->store, new WorkerCommandExecutor(static fn(): string => ''), getenv()) extends WorkerLifecycle {
            public function invoke(array $record, string $action): array
            {
                throw new \RuntimeException('create transport interrupted');
            }
        };
        $record = ['phase' => 'prepared', 'namespace' => 'household-proof'];
        try {
            $lifecycle->start($record);
            self::fail('Unconfirmed creation accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('create transport interrupted', $error->getMessage());
        }
        self::assertSame('create_unconfirmed', $this->store->load()['phase']);
        $this->expectExceptionMessage('already attempted');
        $lifecycle->start($record);
    }
}
