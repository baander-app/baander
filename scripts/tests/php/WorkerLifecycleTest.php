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

    public function testOperatorReceiptCombinesShutdownPeakWithFinalDockerState(): void
    {
        [$lifecycle, $record] = $this->measuredOperator();
        self::assertSame(['state' => 'ready'], $lifecycle->invoke($record, 'status'));
        $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
        self::assertCount(1, $paths);
        $receipt = AtomicFile::readJson($paths[0]);
        self::assertSame('complete', $receipt['status']);
        self::assertSame(10485760, $receipt['cgroup']['memoryPeakBytes']);
        self::assertSame(false, $receipt['docker']['oomKilled']);
        self::assertSame(536870912, $receipt['limits']['memoryMaxBytes']);
        self::assertSame(1700, $receipt['startedMonotonic']);
        self::assertSame($record['operator_image'], $receipt['imageId']);
        self::assertSame(0600, fileperms($paths[0]) & 0777);
        self::assertStringNotContainsString('private-password', file_get_contents($paths[0]));
    }

    public function testMissingOperatorShutdownMeasurementStaysIncomplete(): void
    {
        [$lifecycle, $record] = $this->measuredOperator(marker: false);
        self::assertSame(['state' => 'ready'], $lifecycle->invoke($record, 'status'));
        $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
        self::assertCount(1, $paths);
        $receipt = AtomicFile::readJson($paths[0]);
        self::assertSame('incomplete', $receipt['status']);
        self::assertNull($receipt['cgroup']);
        self::assertContains('shutdown-measurement-missing', $receipt['errors']);
    }

    public function testHostCgroupNamespaceCannotProduceACompleteOperatorReceipt(): void
    {
        [$lifecycle, $record] = $this->measuredOperator(cgroupNamespace: 'host');
        $lifecycle->invoke($record, 'status');
        $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
        $receipt = AtomicFile::readJson($paths[0]);
        self::assertSame('incomplete', $receipt['status']);
        self::assertContains('container-state-unconfirmed', $receipt['errors']);
    }

    public function testOomFailureRetainsFinalStateWithoutFabricatingPeak(): void
    {
        [$lifecycle, $record] = $this->measuredOperator(marker: false, oom: true);
        try {
            $lifecycle->invoke($record, 'status');
            self::fail('Failed operator accepted.');
        } catch (\RuntimeException) {
        }
        $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
        self::assertCount(1, $paths);
        $receipt = AtomicFile::readJson($paths[0]);
        self::assertSame('incomplete', $receipt['status']);
        self::assertTrue($receipt['docker']['oomKilled']);
        self::assertSame(137, $receipt['docker']['exitCode']);
        self::assertNull($receipt['cgroup']);
    }

    /** @return array{WorkerLifecycle,array<string,mixed>} */
    private function measuredOperator(bool $marker = true, bool $oom = false, string $cgroupNamespace = 'private'): array
    {
        $record = ['directory' => $this->directory . '/boot', 'namespace' => 'household-proof', 'boot_id' => str_repeat('a', 32),
            'network' => 'household-network', 'socket' => '/var/run/docker.sock', 'operator_image' => 'sha256:' . str_repeat('b', 64),
            'layout' => ['version' => 1, 'root' => '/srv/baander'], 'operator_memory_mib' => 512,
            'target' => DevelopmentCommandExecutor::target($this->directory . '/docker')];
        AtomicFile::writeJson($record['directory'] . '/manifest.json', ['mediaMounts' => []]);
        AtomicFile::writeJson($record['directory'] . '/credentials.json', ['controllerDatabaseUrl' => 'private-password']);
        $runner = new WorkerCommandExecutor(static function (array $arguments) use ($record, $marker, $oom, $cgroupNamespace): string|\Baander\Tooling\Process\ProcessResult {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (in_array('inspect', $arguments, true) && str_contains(implode(' ', $arguments), 'cgroupNamespace')) {
                return json_encode(['id' => $arguments[array_key_last($arguments)], 'image' => $record['operator_image'], 'status' => 'exited',
                    'running' => false, 'exitCode' => $oom ? 137 : 0, 'oomKilled' => $oom, 'startedAt' => '2026-11-02T10:00:00Z', 'finishedAt' => '2026-11-02T10:00:01Z',
                    'memory' => 536870912, 'memorySwap' => 536870912, 'nanoCpus' => 0, 'pidsLimit' => null, 'cpuSet' => '', 'cgroupNamespace' => $cgroupNamespace], JSON_THROW_ON_ERROR);
            }
            if (in_array('worker-controller', $arguments, true)) {
                self::assertSame('private', $arguments[array_search('--cgroupns', $arguments, true) + 1]);
                $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
                $receipt = $paths === [] ? ['nonce' => 'absent'] : AtomicFile::readJson($paths[0]);
                $metrics = ['version' => 1, 'nonce' => $receipt['nonce'], 'startedMonotonic' => 1700.0, 'finishedMonotonic' => 1701.0,
                    'startedEpochSeconds' => 1793613600.0, 'finishedEpochSeconds' => 1793613601.0, 'errors' => [],
                    'cgroup' => ['identity' => '27:42', 'path' => '/', 'memoryCurrentBytes' => 7340032, 'memoryPeakBytes' => 10485760, 'memoryMaxBytes' => 536870912,
                        'memoryEvents' => ['oom' => 0, 'oom_kill' => 0], 'swapCurrentBytes' => 0, 'swapPeakBytes' => 0, 'swapMaxBytes' => 0,
                        'pidsCurrent' => 2, 'pidsPeak' => null, 'pidsMax' => 'max', 'unwanted' => 'private-password']];
                $stderr = $marker ? "\nBAANDER_OPERATOR_MEASURE_V1:" . $receipt['nonce'] . ':' . json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n" : '';
                return new \Baander\Tooling\Process\ProcessResult($oom ? 137 : 0, null, false, false, '{"success":true,"action":"status","result":{"state":"ready"}}', $stderr, 1.0);
            }
            return '';
        });
        return [new WorkerLifecycle($this->store, $runner, getenv()), $record];
    }

    public function testAlreadyRetiredBootRemovesOnlyItsVerifiedControllerTag(): void
    {
        $record = $this->retiredRecord();
        $tag = $record['controller_tag'];
        $present = true;
        $removed = [];
        $runner = new DevDataRunner(static function (array $arguments) use (&$present, &$removed, $record, $tag): string|\Baander\Tooling\Process\ProcessResult {
            $args = array_slice($arguments, 3);
            if ($args[0] === 'info') {
                return '{"ID":"daemon"}';
            }
            if (array_slice($args, 0, 2) === ['image', 'inspect']) {
                return $present ? json_encode([['Id' => $record['operator_image']]], JSON_THROW_ON_ERROR)
                    : new \Baander\Tooling\Process\ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $tag, 0);
            }
            if (array_slice($args, 0, 2) === ['image', 'rm']) {
                $removed[] = $args[2];
                $present = false;
            }
            return '';
        });
        $lifecycle = new WorkerLifecycle($this->store, $runner, getenv());
        $lifecycle->retire($record);
        $lifecycle->retire($record);
        self::assertSame([$tag], $removed);
        self::assertTrue($this->store->load()['controller_tag_cleaned']);
        self::assertSame('retired', $record['phase']);
    }

    public function testRetaggedControllerAliasCannotAuthorizeForeignImageRemoval(): void
    {
        $record = $this->retiredRecord();
        $runner = new DevDataRunner(static fn(array $arguments): string => in_array('info', $arguments, true)
            ? '{"ID":"daemon"}' : json_encode([['Id' => 'sha256:' . str_repeat('c', 64)]], JSON_THROW_ON_ERROR));
        $this->expectExceptionMessage('controller tag no longer identifies');
        try {
            new WorkerLifecycle($this->store, $runner, getenv())->retire($record);
        } finally {
            self::assertFalse(array_any($runner->calls, static fn(array $call): bool => in_array('rm', $call['arguments'], true)));
        }
    }

    public function testControllerCleanupFailureCanRetryAfterDurableRetirement(): void
    {
        $record = $this->retiredRecord();
        $tag = $record['controller_tag'];
        $present = true;
        $attempts = 0;
        $runner = new DevDataRunner(static function (array $arguments) use (&$present, &$attempts, $record, $tag): string|\Baander\Tooling\Process\ProcessResult {
            $args = array_slice($arguments, 3);
            if ($args[0] === 'info') {
                return '{"ID":"daemon"}';
            }
            if (array_slice($args, 0, 2) === ['image', 'inspect']) {
                return $present ? json_encode([['Id' => $record['operator_image']]], JSON_THROW_ON_ERROR)
                    : new \Baander\Tooling\Process\ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $tag, 0);
            }
            if (array_slice($args, 0, 2) === ['image', 'rm']) {
                if (++$attempts === 1) {
                    return new \Baander\Tooling\Process\ProcessResult(1, null, false, false, '', 'remove failed', 0);
                }
                $present = false;
            }
            return '';
        });
        $this->store->save($record);
        $lifecycle = new WorkerLifecycle($this->store, $runner, getenv());
        try {
            $lifecycle->retire($record);
            self::fail('Removal failure accepted.');
        } catch (\Baander\Tooling\Process\CommandFailure) {
            self::assertSame('retired', $this->store->load()['phase']);
        }
        $lifecycle->retire($record);
        self::assertSame(2, $attempts);
        self::assertTrue($record['controller_tag_cleaned']);
    }

    public function testOldRetiredRecordDerivesOnlyItsCanonicalControllerTag(): void
    {
        $record = $this->retiredRecord();
        unset($record['controller_tag']);
        $tag = 'baander-worker-controller:household-proof-' . $record['boot_id'];
        $runner = new DevDataRunner(static fn(array $arguments): string|\Baander\Tooling\Process\ProcessResult => in_array('info', $arguments, true)
            ? '{"ID":"daemon"}' : (in_array('inspect', $arguments, true)
                ? new \Baander\Tooling\Process\ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $tag, 0) : ''));
        new WorkerLifecycle($this->store, $runner, getenv())->retire($record);
        self::assertTrue($record['controller_tag_cleaned']);
        self::assertContains($tag, $runner->calls[1]['arguments']);
    }

    /** @return array<string,mixed> */
    private function retiredRecord(): array
    {
        return ['phase' => 'retired', 'namespace' => 'household-proof', 'boot_id' => str_repeat('a', 32),
            'operator_image' => 'sha256:' . str_repeat('b', 64),
            'target' => DevelopmentCommandExecutor::target($this->directory . '/docker'),
            'controller_tag' => 'baander-worker-controller:household-proof-' . str_repeat('a', 32)];
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
