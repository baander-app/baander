<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\Workers;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Fixtures/dev-data.php';

final class DevelopmentWorkersTest extends DevDataTestCase
{
    /** @param \Closure(array<string,mixed>,string):array<string,mixed> $callback */
    private function lifecycle(\Closure $callback, ?Runner $runner = null): Workers
    {
        return new class ($this->store, $runner ?? $this->catalogRunner([]), $callback) extends Workers {
            /** @param \Closure(array<string,mixed>,string):array<string,mixed> $callback */
            public function __construct(\Baander\Tooling\Development\StateStore $store, Runner $runner, private readonly \Closure $callback)
            {
                parent::__construct($store, $runner);
            }
            public function invoke(array $instance, string $action): array
            {
                return ($this->callback)($instance, $action);
            }
        };
    }
    /** @return array<string,mixed> */
    private function workerInstance(): array
    {
        $directory = $this->root . '/workers';
        AtomicFile::writeJson(
            $directory . '/manifest.json',
            [
                'namespace' => 'fixture-project',
                'bootId' => 'saved-boot',
                'mediaMounts' => [],
                'imageId' => 'worker-image',
            ],
        );
        AtomicFile::writeJson($directory . '/credentials.json', ['private' => 'secret']);
        return $this->instance + [
            'phase' => 'activating',
            'workers' => [
                'directory' => $directory,
                'operator_image' => 'sha256:saved',
                'boot_id' => 'saved-boot',
                'socket' => '/var/run/docker.sock',
            ],
        ];
    }
    /** @return array<string,mixed> */
    private function unregisteredStatus(): array
    {
        return [
            'namespace' => 'fixture-project',
            'bootId' => 'saved-boot',
            'retirement' => 'unregistered',
            'reservation' => 'none',
            'units' => [],
        ];
    }

    public function testUncertainCreateIsDurableAndCannotBeRetriedOrStarted(): void
    {
        $instance = $this->workerInstance();
        $actions = [];
        $workers = $this->lifecycle(static function (array $instance, string $action) use (&$actions): array {
            $actions[] = $action;
            throw new WorkflowError('unconfirmed');
        });
        try {
            $workers->start($instance);
            self::fail('Unconfirmed create accepted');
        } catch (WorkflowError) {
        }
        self::assertSame(['create'], $actions);
        self::assertSame('create_unconfirmed', $this->store->load('persistent')['workers']['phase']);
        self::assertSame('create_unconfirmed', $instance['workers']['phase']);
        try {
            $workers->start($instance);
            self::fail('Create retried');
        } catch (WorkflowError) {
        }
        self::assertSame(['create'], $actions);
    }

    public function testFailedRetirementPreservesSavedController(): void
    {
        $instance = $this->workerInstance();
        $workers = $this->lifecycle(static fn(): array => throw new WorkflowError('unconfirmed'));
        try {
            $workers->retire($instance);
            self::fail('Unconfirmed retirement accepted');
        } catch (WorkflowError) {
        }
        self::assertSame('sha256:saved', $this->store->load('persistent')['workers']['operator_image']);
        self::assertSame('retirement_unconfirmed', $this->store->load('persistent')['workers']['phase']);
    }

    public function testUnregisteredBootWithoutReservationUnitsOrContainersRetiresLocally(): void
    {
        $instance = $this->workerInstance();
        $status = $this->unregisteredStatus();
        $actions = [];
        $runner = new DevDataRunner(static fn(array $arguments): string => in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '');
        $workers = $this->lifecycle(
            static function (array $instance, string $action) use (&$actions, $status): array {
                $actions[] = $action;
                return $status;
            },
            $runner,
        );
        $workers->retire($instance);
        self::assertSame(['status'], $actions);
        self::assertSame('retired', $instance['workers']['phase']);
        self::assertCount(2, $runner->calls);
        self::assertContains('label=app.baander.worker.boot-id=saved-boot', $runner->calls[1]['arguments']);
    }

    /** @return iterable<string,array{bool}> */
    public static function orphanQueries(): iterable
    {
        yield 'orphan' => [false];
        yield 'query failure' => [true];
    }
    #[DataProvider('orphanQueries')]
    public function testUnregisteredOrphanOrQueryFailureBlocksCleanup(bool $queryFailure): void
    {
        $instance = $this->workerInstance();
        $status = $this->unregisteredStatus();
        $runner = new DevDataRunner(static function (array $arguments) use ($queryFailure): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if ($queryFailure) {
                throw new WorkflowError('query failed');
            }
            return 'orphan-id';
        });
        try {
            $this->lifecycle(static fn(): array => $status, $runner)->retire($instance);
            self::fail('Unsafe cleanup accepted');
        } catch (WorkflowError) {
        }
        self::assertSame('retirement_unconfirmed', $this->store->load('persistent')['workers']['phase']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function changedStatus(): iterable
    {
        yield 'namespace' => [['namespace' => 'different']];
        yield 'boot' => [['bootId' => 'different']];
        yield 'retirement' => [['retirement' => 'pending']];
        yield 'reservation' => [['reservation' => 'another_boot']];
        yield 'units' => [['units' => ['control' => ['createClaimed' => true]]]];
    }
    /** @param array<string,mixed> $change */
    #[DataProvider('changedStatus')]
    public function testUnregisteredCleanupRequiresMatchingIdentityAndNoReservationOrUnits(array $change): void
    {
        $instance = $this->workerInstance();
        $status = array_replace($this->unregisteredStatus(), $change);
        $actions = [];
        $workers = $this->lifecycle(static function (array $instance, string $action) use (&$actions, $status): array {
            $actions[] = $action;
            return $action === 'status' ? $status : ['state' => 'pending'];
        });
        try {
            $workers->retire($instance);
            self::fail('Unconfirmed recovery accepted');
        } catch (WorkflowError) {
        }
        self::assertSame(['status', 'recover'], $actions);
        self::assertSame('retirement_unconfirmed', $instance['workers']['phase']);
    }

    public function testSuccessfulStartWaitsForReadinessObservation(): void
    {
        $instance = $this->workerInstance();
        $workers = $this->lifecycle(static fn(array $instance, string $action): array => $action === 'readiness' ? ['readiness' => 'ready'] : []);
        $workers->start($instance);
        self::assertSame('started', $instance['workers']['phase']);
        $workers->ready($instance, timeout: 0);
        self::assertSame('ready', $instance['workers']['phase']);
    }

    public function testPrivateDocumentsTravelOnlyInBoundedStdin(): void
    {
        $instance = $this->workerInstance();
        $runner = new DevDataRunner(static fn(array $arguments): string => in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '{"success":true,"action":"status","result":{"state":"retired"}}');
        self::assertSame(['state' => 'retired'], new Workers($this->store, $runner)->invoke($instance, 'status'));
        $call = $runner->calls[1];
        $arguments = implode(' ', $call['arguments']);
        self::assertStringNotContainsString($instance['workers']['directory'], $arguments);
        self::assertStringNotContainsString('secret', $arguments);
        self::assertStringContainsString('label=disable', $arguments);
        self::assertStringNotContainsString('--cap-add', $arguments);
        self::assertStringContainsString('mode=0700', $arguments);
        $documents = json_decode($call['input'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['manifest', 'credentials'], array_keys($documents));
        self::assertSame(['private' => 'secret'], json_decode($documents['credentials'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOversizeCredentialsAreRefusedBeforeOperatorLaunch(): void
    {
        $instance = $this->workerInstance();
        file_put_contents($instance['workers']['directory'] . '/credentials.json', str_repeat('x', 8193));
        $runner = $this->catalogRunner([]);
        try {
            new Workers($this->store, $runner)->invoke($instance, 'status');
            self::fail('Oversize input accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('bounded file size', $error->getMessage());
        }
        self::assertCount(1, $runner->calls);
    }

    public function testChangedOrCommaContainingSourceIsRefused(): void
    {
        mkdir($this->root . '/library');
        symlink($this->root . '/library', $this->root . '/alias');
        foreach ([$this->root . '/alias', $this->root . '/missing', $this->root . '/comma,path'] as $source) {
            if (str_contains($source, ',')) {
                mkdir($source);
            }
            try {
                Workers::sourceMountArguments(['mediaMounts' => [['role' => 'source', 'source' => $source]]]);
                self::fail('Unsafe source accepted');
            } catch (WorkflowError) {
                $this->addToAssertionCount(1);
            }
        }
        $arguments = Workers::sourceMountArguments(['mediaMounts' => [['role' => 'source', 'source' => $this->root . '/library']]]);
        self::assertStringContainsString('readonly,bind-propagation=rprivate,bind-recursive=readonly', $arguments[1]);
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        return [
            'APP_SECRET' => 'worker-parser-test',
            'DATABASE_URL' => 'postgresql://baander:test-only@database:5432/worker_operator_test?serverVersion=18&charset=utf8',
            'REDIS_URL' => 'redis://default:test-only@redis:6379',
            'MESSENGER_TRANSPORT_DSN' => 'redis://default:test-only@redis:6379/messages',
            'MEDIA_STORAGE_PATH' => '/storage/media',
            'CONVERT_STORAGE_PATH' => '/storage/conversions',
            'VAPID_PRIVATE_KEY' => 'instance-private-key',
            'VAPID_PUBLIC_KEY' => 'instance-public-key',
            'STREAM_URL_HMAC_SECRET' => 'instance-stream-secret',
        ];
    }
    private function preparationRunner(bool $reject = false): DevDataRunner
    {
        return new DevDataRunner(static function (array $arguments) use ($reject): string|\Baander\Tooling\Process\ProcessResult {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon","NCPU":2,"MemTotal":17179869184}';
            }
            if (in_array('context', $arguments, true)) {
                return '[{"Endpoints":{"docker":{"Host":"unix:///var/run/docker.sock"}}}]';
            }
            if ($reject && in_array('-i', $arguments, true)) {
                return DevDataRunner::result('', 1);
            }
            return '{}';
        });
    }

    public function testAdoptedReadonlySourceIsPreservedAndIpcIsPrivate(): void
    {
        mkdir($this->root . '/library');
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', $this->environment());
        $this->instance['state_mounts']['media'] = [
            'type' => 'bind',
            'source' => $this->root . '/library',
            'target' => '/storage/media',
            'read_only' => true,
        ];
        $runner = $this->preparationRunner();
        new Workers($this->store, $runner)->prepare($this->instance, $this->release);
        $manifest = AtomicFile::readJson($this->instance['workers']['directory'] . '/manifest.json');
        $source = array_find($manifest['mediaMounts'], static fn(array $mount): bool => $mount['target'] === '/storage/media');
        self::assertSame(
            [
                'readOnly' => true,
                'role' => 'source',
                'source' => $this->root . '/library',
                'target' => '/storage/media',
            ],
            $source,
        );
        $initialization = array_find($runner->calls, static fn(array $call): bool => in_array('--cap-add', $call['arguments'], true));
        self::assertContains('CHOWN', $initialization['arguments']);
        self::assertContains('--read-only', $initialization['arguments']);
        self::assertContains(
            'type=volume,src=' . $manifest['ipcVolume'] . ',dst=/run/baander-worker,volume-nocopy',
            $initialization['arguments'],
        );
        self::assertStringContainsString('chmod($directory, 0700)', end($initialization['arguments']));
        self::assertTrue(array_any(
            $runner->calls,
            fn(array $call): bool => in_array(
                'baander-development-controller:' . $this->instance['project'] . '-' . $manifest['bootId'],
                $call['arguments'],
                true,
            ),
        ));
        self::assertSame(0700, fileperms($this->instance['workers']['directory']) & 0777);
    }

    public function testDerivedBindIsRefusedBeforeSavingWorkerPointer(): void
    {
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', $this->environment());
        $this->instance['state_mounts']['storage'] = ['type' => 'bind', 'source' => $this->root, 'target' => '/var/www/html/storage'];
        try {
            new Workers($this->store, $this->preparationRunner())->prepare($this->instance, $this->release);
            self::fail('Derived bind accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('requires a named volume', $error->getMessage());
        }
        self::assertArrayNotHasKey('workers', $this->instance);
    }

    public function testProductionValidationFailureDoesNotSaveWorkerPointer(): void
    {
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', $this->environment());
        try {
            new Workers($this->store, $this->preparationRunner(true))->prepare($this->instance, $this->release);
            self::fail('Rejected manifest accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('overlapping media paths', $error->getMessage());
        }
        self::assertArrayNotHasKey('workers', $this->instance);
    }

    /** @return iterable<string,array{string}> */
    public static function observedPairs(): iterable
    {
        foreach ([
            'running',
            'control stopped',
            'execution stopped',
            'control absent',
            'execution absent',
            'empty',
            'namespace changed',
            'boot changed',
            'image changed',
            'duplicate role',
            'invalid state',
            'manifest changed',
        ] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('observedPairs')]
    public function testRunningObservationRequiresTheRecordedBootPair(string $case): void
    {
        $instance = $this->workerInstance();
        $containers = [];
        foreach (['control', 'execution'] as $role) {
            if ($case === $role . ' absent' || $case === 'empty') {
                continue;
            }
            $containers[] = [
                'Image' => 'worker-image',
                'Config' => ['Labels' => [
                    'app.baander.worker.namespace' => 'fixture-project',
                    'app.baander.worker.boot-id' => 'saved-boot',
                    'app.baander.worker.role' => $role,
                ]],
                'State' => ['Running' => $case !== $role . ' stopped', 'Health' => ['Status' => 'unhealthy']],
            ];
        }
        if ($case === 'namespace changed') {
            $containers[0]['Config']['Labels']['app.baander.worker.namespace'] = 'different';
        }
        if ($case === 'boot changed') {
            $containers[0]['Config']['Labels']['app.baander.worker.boot-id'] = 'different';
        }
        if ($case === 'image changed') {
            $containers[0]['Image'] = 'different';
        }
        if ($case === 'duplicate role') {
            $containers[1]['Config']['Labels']['app.baander.worker.role'] = 'control';
        }
        if ($case === 'invalid state') {
            $containers[0]['State']['Running'] = 'true';
        }
        if ($case === 'manifest changed') {
            AtomicFile::writeJson(
                $instance['workers']['directory'] . '/manifest.json',
                ['namespace' => 'different', 'bootId' => 'saved-boot'],
            );
        }
        $runner = new DevDataRunner(static function (array $arguments) use ($containers): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (in_array('ps', $arguments, true)) {
                return $containers === [] ? '' : implode("\n", array_keys($containers));
            }
            return json_encode($containers, JSON_THROW_ON_ERROR);
        });
        if (in_array(
            $case,
            [
                'namespace changed',
                'boot changed',
                'image changed',
                'duplicate role',
                'invalid state',
                'manifest changed',
            ],
            true,
        )) {
            $this->expectException(WorkflowError::class);
        }
        self::assertSame($case === 'running', new Workers($this->store, $runner)->running($instance));
    }

    /** @return iterable<string,array{string}> */
    public static function qualifiedTopologies(): iterable
    {
        yield 'default source' => ['default'];
        yield 'explicit source' => ['explicit'];
        yield 'replace default' => ['replace'];
        yield 'overlap refused' => ['overlap'];
        yield 'derived bind refused' => ['derived'];
        yield 'private transfer' => ['invoke'];
    }
    #[DataProvider('qualifiedTopologies')]
    public function testGeneratedManifestPassesProductionRecipes(string $topology): void
    {
        $image = getenv('BAANDER_TEST_OPERATOR_IMAGE');
        if (!$image) {
            self::markTestSkipped('Requires qualified operator image and disposable Docker resources.');
        }
        if ($topology === 'invoke' && !getenv('BAANDER_TEST_WORKER_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable migrated PostgreSQL.');
        }
        $runner = new Runner();
        $context = getenv('BAANDER_TEST_DOCKER_CONTEXT') ?: 'default';
        $docker = ['docker', '--context', $context];
        $daemon = json_decode(
            $runner->run([...$docker, 'info', '--format', '{{json .}}'])->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $image = trim($runner->run([...$docker, 'image', 'inspect', '--format', '{{.Id}}', $image])->stdout);
        $project = 'baander-worker-parser-' . bin2hex(random_bytes(6));
        $this->instance['project'] = $project;
        $this->instance['hostname'] = 'workers.baander.app';
        $this->instance['network'] = getenv('BAANDER_TEST_WORKER_NETWORK') ?: $project;
        $this->instance['docker'] = ['executable' => 'docker', 'context' => $context, 'daemon_id' => $daemon['ID']];
        foreach (['app', 'worker', 'operator', 'postgres', 'redis', 'nginx', 'tool'] as $key) {
            $this->release[$key . '_image'] = $image;
        }
        $environment = $this->environment();
        $environment['DATABASE_URL'] = getenv('BAANDER_TEST_WORKER_DATABASE_URL') ?: $environment['DATABASE_URL'];
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', $environment);
        if (in_array($topology, ['explicit', 'replace', 'overlap'], true)) {
            mkdir($this->root . '/library');
            $this->instance['media'] = [[
                'source' => $this->root . '/library',
                'target' => match ($topology) {
                    'explicit' => '/media/library',
                    'replace' => '/storage/media',
                    default => '/storage/media/nested',
                },
            ]];
        }
        if ($topology === 'derived') {
            $this->instance['state_mounts']['storage'] = ['type' => 'bind', 'source' => $this->root, 'target' => '/var/www/html/storage'];
        }
        try {
            $workers = new Workers($this->store, $runner);
            if (in_array($topology, ['overlap', 'derived'], true)) {
                try {
                    $workers->prepare($this->instance, $this->release);
                    self::fail('Unsafe production topology accepted');
                } catch (WorkflowError $error) {
                    self::assertStringContainsString(
                        $topology === 'overlap' ? 'overlapping media paths' : 'requires a named volume',
                        $error->getMessage(),
                    );
                }
                self::assertArrayNotHasKey('workers', $this->instance);
                return;
            }
            $workers->prepare($this->instance, $this->release);
            $directory = $this->instance['workers']['directory'];
            $manifest = AtomicFile::readJson($directory . '/manifest.json');
            $mounts = array_column($manifest['mediaMounts'], null, 'target');
            self::assertSame('source', $mounts['/storage/media']['role']);
            self::assertTrue($mounts['/storage/media']['readOnly']);
            foreach (['/storage/conversions', '/var/www/html/storage', '/var/www/html/var/transcode_state'] as $target) {
                self::assertSame('derived', $mounts[$target]['role']);
                self::assertFalse($mounts[$target]['readOnly']);
            }
            if (in_array($topology, ['explicit', 'replace'], true)) {
                self::assertSame(
                    $this->root . '/library',
                    $mounts[$topology === 'explicit' ? '/media/library' : '/storage/media']['source'],
                );
            }
            $credentials = AtomicFile::readJson($directory . '/credentials.json')['runtimeEnvironment'];
            foreach (['VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY', 'STREAM_URL_HMAC_SECRET'] as $key) {
                self::assertSame($environment[$key], $credentials[$key]);
            }
            self::assertSame('https://workers.baander.app', $credentials['APP_URL']);
            self::assertSame($credentials['APP_URL'], $credentials['DEFAULT_URI']);
            if ($topology === 'invoke') {
                self::assertNotSame(0, stat('/proc/self')['uid'], 'Exercise the nonroot host to root operator boundary.');
                self::assertSame(stat('/proc/self')['uid'], fileowner($directory));
                self::assertSame(0700, fileperms($directory) & 0777);
                self::assertSame(0600, fileperms($directory . '/credentials.json') & 0777);
                $status = $workers->invoke($this->instance, 'status');
                self::assertSame('unregistered', $status['retirement']);
                self::assertSame('none', $status['reservation']);
            }
        } finally {
            $volumes = $runner->run([...$docker, 'volume', 'ls', '--format', '{{.Name}}'], check: false);
            foreach (explode("\n", $volumes->stdout) as $volume) {
                if (str_starts_with($volume, $project . '-')) {
                    $runner->run([...$docker, 'volume', 'rm', $volume], check: false);
                }
            }
        }
    }
}
