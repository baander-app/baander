<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use App\Shared\Infrastructure\Worker\DeploymentOperatorManifest;
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
            'version' => 2,
            'deployment' => json_decode(new \App\Shared\Application\DTO\DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320],
        ]);
        return new WorkerConfiguration(...array_replace([
            'namespace' => 'preset-proof',
            'target' => DevelopmentCommandExecutor::target($this->directory . '/docker'),
            'network' => 'preset-network',
            'workerImage' => 'sha256:' . str_repeat('a', 64),
            'operatorImage' => 'sha256:' . str_repeat('b', 64),
            'layout' => ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']),
            'runtimeEnvironment' => ['APP_ENV' => 'prod', 'DATABASE_URL' => 'postgresql://baander@postgres/baander'],
            'sourceMountPolicy' => 'production',
            'mediaMounts' => [
                ['volume' => 'preset-media', 'target' => '/storage/media', 'role' => 'source', 'readOnly' => true],
                ['volume' => 'preset-derived', 'target' => '/srv/baander/storage', 'role' => 'derived', 'readOnly' => false],
            ],
            'resourceLabels' => ['app.baander.preset.owner' => 'preset-proof'],
            'profile' => $profile,
        ], $overrides));
    }

    public function testProductionPreparationWritesAnUnlimitedManifestAndPersistsIntentBeforeCreatingVolumes(): void
    {
        $runner = new WorkerCommandExecutor(function (array $arguments): string {
            if (in_array('info', $arguments, true)) {
                // A single small CPU and little memory still admit a worker pair.
                return '{"ID":"daemon","NCPU":1,"MemTotal":1073741824,"SecurityOptions":["name=seccomp,profile=builtin","name=cgroupns"]}';
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
        $configuration = $this->configuration();
        $lifecycle = new WorkerLifecycle($this->store, $runner, getenv());
        $lifecycle->prepare($record, $configuration);
        self::assertSame('prepared', $record['phase']);
        self::assertSame($record, $this->store->load());
        self::assertSame($configuration->target, $record['target']);
        self::assertSame('preset-network', $record['network']);
        self::assertArrayNotHasKey('operator_memory_mib', $record);
        $manifest = AtomicFile::readJson($record['directory'] . '/manifest.json');
        $fields = ['version', 'sourceMountPolicy', 'namespace', 'bootId', 'daemonId', 'imageId', 'network', 'dockerBinary', 'dockerEndpoint',
            'autoscaling', 'deploymentProfile', 'deviceGroup', 'scheduledConsoleMiB', 'ipcVolume', 'mediaMounts'];
        $saved = array_keys($manifest);
        sort($fields);
        sort($saved);
        self::assertSame($fields, $saved);
        self::assertSame(9, $manifest['version']);
        self::assertNull($manifest['deviceGroup']);
        self::assertSame('production', $manifest['sourceMountPolicy']);
        self::assertSame(320, $manifest['scheduledConsoleMiB']);
        self::assertSame([], WorkerLifecycle::sourceMountArguments($manifest));
        self::assertEquals($configuration->mediaMounts, $manifest['mediaMounts']);
        self::assertSame(['state' => 'ready'], $lifecycle->invoke($record, 'status'));
        $operators = array_filter($runner->calls, static fn(array $call): bool => in_array('worker-controller', $call['arguments'], true) || ($call['arguments'][3] ?? null) === 'create');
        self::assertGreaterThanOrEqual(3, count($operators));
        foreach ($operators as $call) {
            foreach (['--memory', '--memory-swap', '--pids-limit', '--cpus', '--cpuset-cpus', '--cpu-shares'] as $limit) {
                self::assertNotContains($limit, $call['arguments']);
            }
        }
    }

    public function testPreparedManifestIsTheShapeTheApplicationOperatorAccepts(): void
    {
        $runner = new WorkerCommandExecutor(static function (array $arguments): string {
            if (in_array('volume', $arguments, true) && in_array('create', $arguments, true)) {
                return $arguments[array_key_last($arguments)];
            }
            return in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '';
        });
        $deployment = AtomicFile::readJson(dirname(__DIR__, 3) . '/tests/Fixtures/Worker/deployment-profile.json');
        $profile = ImageProfile::fromArray(['version' => 2, 'deployment' => $deployment,
            'autoscaling' => AtomicFile::readJson(dirname(__DIR__, 3) . '/tests/Fixtures/Worker/autoscaling-profile.json'),
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320]]);
        $record = null;
        new WorkerLifecycle($this->store, $runner, getenv())->prepare($record, $this->configuration(['profile' => $profile, 'mediaMounts' => [
            ['volume' => 'preset-media', 'target' => '/storage/media', 'role' => 'source', 'readOnly' => true],
            ['volume' => 'preset-derived', 'target' => '/srv/baander/storage', 'role' => 'derived', 'readOnly' => false],
            ['volume' => 'preset-state', 'target' => '/srv/baander/var/transcode_state', 'role' => 'derived', 'readOnly' => false],
        ]]));
        $contents = file_get_contents($record['directory'] . '/manifest.json');
        self::assertIsString($contents);
        $manifest = DeploymentOperatorManifest::fromJson($contents, '/srv/baander');
        self::assertSame(320 * 1024 ** 2, $manifest->scheduledConsoleBytes);
        self::assertSame('transcode', $manifest->deploymentProfile->capability);
    }

    public function testHardwareManifestMapsTheDeviceAndItsHostGroupIntoExecutionOnly(): void
    {
        $runner = new WorkerCommandExecutor(static function (array $arguments): string {
            if (in_array('volume', $arguments, true) && in_array('create', $arguments, true)) {
                return $arguments[array_key_last($arguments)];
            }
            return in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '';
        });
        // /dev/null stands in for the render node: a character device whose group the host reports.
        $deployment = new \App\Shared\Application\DTO\DeploymentProfile(hardware: new \App\Shared\Application\DTO\HardwareEncoder('vaapi', '/dev/null'));
        $profile = ImageProfile::fromArray(['version' => 2, 'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => AtomicFile::readJson(dirname(__DIR__, 3) . '/tests/Fixtures/Worker/autoscaling-profile.json'),
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320]]);
        $mounts = [
            ['volume' => 'preset-media', 'target' => '/storage/media', 'role' => 'source', 'readOnly' => true],
            ['volume' => 'preset-derived', 'target' => '/srv/baander/storage', 'role' => 'derived', 'readOnly' => false],
            ['volume' => 'preset-state', 'target' => '/srv/baander/var/transcode_state', 'role' => 'derived', 'readOnly' => false],
        ];
        $record = null;
        new WorkerLifecycle($this->store, $runner, getenv())->prepare($record, $this->configuration(['profile' => $profile, 'mediaMounts' => $mounts]));
        $contents = file_get_contents($record['directory'] . '/manifest.json');
        self::assertIsString($contents);
        $manifest = DeploymentOperatorManifest::fromJson($contents, '/srv/baander');
        self::assertSame(stat('/dev/null')['gid'], $manifest->deviceGroup);
        self::assertEquals($deployment, $manifest->deploymentProfile);
        $environment = new \App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment(['BAANDER_DEPLOYMENT_PROFILE' => $deployment->toJson()]);
        $execution = $manifest->recipe(\App\Shared\Application\DTO\DeploymentUnit::Execution, $environment);
        $control = $manifest->recipe(\App\Shared\Application\DTO\DeploymentUnit::Control, $environment);
        self::assertSame([['/dev/null'], [stat('/dev/null')['gid']]], [$execution->devices, $execution->groups]);
        self::assertSame([[], []], [$control->devices, $control->groups]);

        $record = null;
        new WorkerLifecycle($this->store, $runner, getenv())->prepare($record, $this->configuration(['profile' => $profile, 'mediaMounts' => $mounts, 'deviceGroup' => 485]));
        self::assertSame(485, AtomicFile::readJson($record['directory'] . '/manifest.json')['deviceGroup']);
    }

    public function testHardwareDeviceThatIsNotADeviceNodeIsRefusedBeforePreparation(): void
    {
        $deployment = new \App\Shared\Application\DTO\DeploymentProfile(hardware: new \App\Shared\Application\DTO\HardwareEncoder('vaapi', '/dev/dri/baander-missing-render-node'));
        $profile = ImageProfile::fromArray(['version' => 2, 'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1], 'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('set hardware.group');
        $this->configuration(['profile' => $profile])->deviceGroup();
    }

    public function testRootlessDaemonIsRefusedBeforeAnythingIsSaved(): void
    {
        $runner = new WorkerCommandExecutor(static fn(): string => '{"ID":"daemon","SecurityOptions":["name=seccomp,profile=builtin","name=rootless"]}');
        $record = null;
        $this->expectExceptionMessage('rootless');
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
        self::assertSame('private', $receipt['docker']['cgroupNamespace']);
        self::assertArrayNotHasKey('limits', $receipt);
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

    public function testOperatorContainerLimitsCannotProduceACompleteReceipt(): void
    {
        foreach ([['memory' => 536870912, 'memorySwap' => 536870912], ['memorySwap' => 536870912], ['nanoCpus' => 1000000000], ['pidsLimit' => 64], ['cpuSet' => '0'],
            ['cgroup' => ['memoryMaxBytes' => 536870912]], ['cgroup' => ['swapMaxBytes' => 0]]] as $limit) {
            [$lifecycle, $record] = $this->measuredOperator(limits: $limit);
            self::assertSame(['state' => 'ready'], $lifecycle->invoke($record, 'status'));
            $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
            self::assertCount(1, $paths);
            $receipt = AtomicFile::readJson($paths[0]);
            self::assertSame('incomplete', $receipt['status'], json_encode($limit, JSON_THROW_ON_ERROR));
            self::assertSame(['container-limit-present'], $receipt['errors']);
            Collector::remove($record['directory']);
        }
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

    /** @param array<string,mixed> $limits container settings, or cgroup values under 'cgroup', that replace the unlimited defaults
     * @return array{WorkerLifecycle,array<string,mixed>}
     */
    private function measuredOperator(bool $marker = true, bool $oom = false, string $cgroupNamespace = 'private', array $limits = []): array
    {
        $record = ['directory' => $this->directory . '/boot', 'namespace' => 'preset-proof', 'boot_id' => str_repeat('a', 32),
            'network' => 'preset-network', 'socket' => '/var/run/docker.sock', 'operator_image' => 'sha256:' . str_repeat('b', 64),
            'layout' => ['version' => 1, 'root' => '/srv/baander'],
            'target' => DevelopmentCommandExecutor::target($this->directory . '/docker')];
        AtomicFile::writeJson($record['directory'] . '/manifest.json', ['mediaMounts' => []]);
        AtomicFile::writeJson($record['directory'] . '/credentials.json', ['controllerDatabaseUrl' => 'private-password']);
        $cgroupLimits = $limits['cgroup'] ?? [];
        unset($limits['cgroup']);
        $runner = new WorkerCommandExecutor(static function (array $arguments) use ($record, $marker, $oom, $cgroupNamespace, $limits, $cgroupLimits): string|\Baander\Tooling\Process\ProcessResult {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (in_array('inspect', $arguments, true) && str_contains(implode(' ', $arguments), 'cgroupNamespace')) {
                return json_encode(['id' => $arguments[array_key_last($arguments)], 'image' => $record['operator_image'], 'status' => 'exited',
                    'running' => false, 'exitCode' => $oom ? 137 : 0, 'oomKilled' => $oom, 'startedAt' => '2026-11-02T10:00:00Z', 'finishedAt' => '2026-11-02T10:00:01Z',
                    'memory' => 0, 'memorySwap' => 0, 'nanoCpus' => 0, 'pidsLimit' => null, 'cpuSet' => '', 'cgroupNamespace' => $cgroupNamespace, ...$limits], JSON_THROW_ON_ERROR);
            }
            if (in_array('worker-controller', $arguments, true)) {
                self::assertSame('private', $arguments[array_search('--cgroupns', $arguments, true) + 1]);
                $paths = glob($record['directory'] . '/operator-measurements/status-*.json');
                $receipt = $paths === [] ? ['nonce' => 'absent'] : AtomicFile::readJson($paths[0]);
                $metrics = ['version' => 1, 'nonce' => $receipt['nonce'], 'startedMonotonic' => 1700.0, 'finishedMonotonic' => 1701.0,
                    'startedEpochSeconds' => 1793613600.0, 'finishedEpochSeconds' => 1793613601.0, 'errors' => [],
                    'cgroup' => [...['identity' => '27:42', 'path' => '/', 'memoryCurrentBytes' => 7340032, 'memoryPeakBytes' => 10485760, 'memoryMaxBytes' => 'max',
                        'memoryEvents' => ['oom' => 0, 'oom_kill' => 0], 'swapCurrentBytes' => 0, 'swapPeakBytes' => 0, 'swapMaxBytes' => 'max',
                        'pidsCurrent' => 2, 'pidsPeak' => null, 'pidsMax' => 'max', 'unwanted' => 'private-password'], ...$cgroupLimits]];
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
        $tag = 'baander-worker-controller:preset-proof-' . $record['boot_id'];
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
        return ['phase' => 'retired', 'namespace' => 'preset-proof', 'boot_id' => str_repeat('a', 32),
            'operator_image' => 'sha256:' . str_repeat('b', 64),
            'target' => DevelopmentCommandExecutor::target($this->directory . '/docker'),
            'controller_tag' => 'baander-worker-controller:preset-proof-' . str_repeat('a', 32)];
    }

    public function testUncertainCreationIsSavedWithoutDevelopmentInstanceState(): void
    {
        $lifecycle = new class ($this->store, new WorkerCommandExecutor(static fn(): string => ''), getenv()) extends WorkerLifecycle {
            public function invoke(array $record, string $action): array
            {
                throw new \RuntimeException('create transport interrupted');
            }
        };
        $record = ['phase' => 'prepared', 'namespace' => 'preset-proof'];
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
