<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\Workers;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Fixtures/dev-data.php';

require_once __DIR__ . '/Fixtures/development-command-executor.php';
require_once __DIR__ . '/Fixtures/development-worker-command-executor.php';

final class DevelopmentWorkersTest extends DevDataTestCase
{
    private bool $retainQualificationEvidence = false;

    protected function tearDown(): void
    {
        if (!$this->retainQualificationEvidence) {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $docker = $this->root . '/docker';
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $docker);
        chmod($docker, 0700);
        AtomicFile::writeJson($this->root . '/daemon.json', ['daemonId' => 'daemon']);
        $this->instance['docker'] = DevelopmentCommandExecutor::target($docker, 'fixture');
        $this->release['target'] = $this->instance['docker'];
    }

    /** @param \Closure(non-empty-list<string>,?string,?array<string,string>):(string|\Baander\Tooling\Process\ProcessResult) $callback */
    private function runner(\Closure $callback): WorkerCommandExecutor
    {
        return new WorkerCommandExecutor($callback);
    }

    /** @param \Closure(array<string,mixed>,string):array<string,mixed> $callback */
    private function lifecycle(\Closure $callback, ?DevelopmentCommandExecutor $runner = null): Workers
    {
        return new class ($this->store, $runner ?? $this->catalogRunner([]), $callback) extends Workers {
            /** @param \Closure(array<string,mixed>,string):array<string,mixed> $callback */
            public function __construct(\Baander\Tooling\Development\StateStore $store, DevelopmentCommandExecutor $runner, private readonly \Closure $callback)
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
                'layout' => ['version' => 1, 'root' => '/var/www/html'],
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
        $runner = $this->runner(static fn(array $arguments): string => in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '');
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
        $runner = $this->runner(static function (array $arguments) use ($queryFailure): string {
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
        $runner = $this->runner(static fn(array $arguments): string => in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '{"success":true,"action":"status","result":{"state":"retired"}}');
        self::assertSame(['state' => 'retired'], new Workers($this->store, $runner)->invoke($instance, 'status'));
        $creation = array_find($runner->calls, static fn(array $call): bool => in_array('create', $call['arguments'], true));
        $call = array_find($runner->calls, static fn(array $call): bool => $call['input'] !== null);
        self::assertNotNull($creation);
        self::assertNotNull($call);
        $arguments = implode(' ', $creation['arguments']);
        self::assertStringNotContainsString($instance['workers']['directory'], $arguments);
        self::assertStringNotContainsString('secret', $arguments);
        self::assertStringContainsString('label=disable', $arguments);
        self::assertStringNotContainsString('--cap-add', $arguments);
        self::assertStringContainsString('mode=0700', $arguments);
        $documents = json_decode($call['input'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['manifest', 'credentials'], array_keys($documents));
        self::assertSame(['private' => 'secret'], json_decode($documents['credentials'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([], glob($this->root . '/resource-*.json'));
        self::assertSame([], glob($instance['workers']['directory'] . '/*-resources.json'));
    }

    public function testMissingSavedSourceDoesNotPreventObservationButStillBlocksCreation(): void
    {
        $instance = $this->workerInstance();
        $path = $instance['workers']['directory'] . '/manifest.json';
        $manifest = AtomicFile::readJson($path);
        $manifest['mediaMounts'] = [['source' => $this->root . '/unavailable-source', 'target' => '/media/movies', 'role' => 'source', 'readOnly' => true]];
        AtomicFile::writeJson($path, $manifest);
        $original = file_get_contents($path);
        $runner = $this->runner(static fn(array $arguments): string => in_array('info', $arguments, true) ? '{"ID":"daemon"}' : '{"success":true,"action":"status","result":{"state":"retired"}}');
        $workers = new Workers($this->store, $runner);
        self::assertSame(['state' => 'retired'], $workers->invoke($instance, 'status'));
        self::assertSame($original, file_get_contents($path));
        $creation = array_find($runner->calls, static fn(array $call): bool => in_array('create', $call['arguments'], true));
        self::assertContains('--read-only', $creation['arguments']);
        self::assertContains($this->root . '/unavailable-source:ro,noexec,nosuid,nodev,size=1m,mode=0555', $creation['arguments']);
        self::assertDirectoryDoesNotExist($this->root . '/unavailable-source');
        $creations = count(array_filter($runner->calls, static fn(array $call): bool => in_array('create', $call['arguments'], true)));
        try {
            $workers->invoke($instance, 'create');
            self::fail('Creation admitted unavailable source media.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('source directory changed', $error->getMessage());
        }
        self::assertSame($creations, count(array_filter($runner->calls, static fn(array $call): bool => in_array('create', $call['arguments'], true))));
    }

    public function testHistoricalRetirementUsesOnlyTheSavedOperatorImageLayout(): void
    {
        $instance = $this->workerInstance();
        unset($instance['workers']['layout']);
        $path = $instance['workers']['directory'] . '/manifest.json';
        $manifest = AtomicFile::readJson($path);
        $manifest['mediaMounts'] = [['source' => $this->root . '/missing-historical-source', 'target' => '/media/movies', 'role' => 'source', 'readOnly' => true]];
        AtomicFile::writeJson($path, $manifest);
        $runner = $this->runner(static function (array $arguments): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (in_array('image', $arguments, true) && in_array('inspect', $arguments, true)) {
                return '[{"Id":"sha256:saved","Config":{"WorkingDir":"/srv/historical-operator","Env":["APP_HOME=/srv/historical-operator"]}}]';
            }
            return '{"success":true,"action":"status","result":{"state":"retired"}}';
        });
        self::assertSame(['state' => 'retired'], new Workers($this->store, $runner)->invoke($instance, 'status'));
        self::assertTrue(array_any($runner->calls, static fn(array $call): bool => in_array('image', $call['arguments'], true)
            && in_array('sha256:saved', $call['arguments'], true)));
        self::assertDirectoryDoesNotExist($this->root . '/missing-historical-source');
    }

    public function testRetirementUsesReadOnlyPrivatePlaceholdersForNestedHistoricalSources(): void
    {
        $source = $this->root . '/unavailable';
        $manifest = ['mediaMounts' => [
            ['source' => $source, 'target' => '/media', 'role' => 'source', 'readOnly' => true],
            ['source' => $source . '/movies', 'target' => '/movies', 'role' => 'source', 'readOnly' => true],
        ]];
        self::assertSame(['--tmpfs', $source . '/movies:ro,noexec,nosuid,nodev,size=1m,mode=0555'], Workers::retirementMountArguments($manifest, '/var/www/html'));
        self::assertDirectoryDoesNotExist($source);
        self::assertSame(['--tmpfs', '/run/media/unavailable:ro,noexec,nosuid,nodev,size=1m,mode=0555'], Workers::retirementMountArguments(['mediaMounts' => [['source' => '/run/media/unavailable', 'target' => '/media', 'role' => 'source', 'readOnly' => true]]], '/var/www/html'));
    }

    public function testRetirementPlaceholdersCannotObscureHelperRuntimePaths(): void
    {
        foreach (['/', '/operator/manifest.json', '/var/run/docker.sock', '/usr/local/bin/php', '/var/www/html/vendor', '/media/../operator'] as $source) {
            try {
                Workers::retirementMountArguments(['mediaMounts' => [['source' => $source, 'target' => '/media', 'role' => 'source', 'readOnly' => true]]], '/var/www/html');
                self::fail('Unsafe helper placeholder accepted.');
            } catch (WorkflowError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHistoricalRecipeIdentityDoesNotNeedSourceContents(): void
    {
        require_once __DIR__ . '/Fixtures/historical-worker-recipe.php';
        $source = $this->root . '/helper-empty-source/movies';
        try {
            \Baander\Tooling\Tests\Historical\DeploymentContainerRecipe::verifySourceDirectory($source);
            self::fail('Historical admission unexpectedly accepted a missing directory.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('existing canonical absolute directory', $error->getMessage());
        }
        mkdir($source, 0700, true);
        $recipe = new \Baander\Tooling\Tests\Historical\DeploymentContainerRecipe(
            'historical-pair',
            str_repeat('a', 32),
            'daemon',
            'sha256:' . str_repeat('b', 64),
            ['/usr/local/bin/php', '/var/www/html/bin/console', 'app:worker'],
            'historical-network',
            128 * 1024 ** 2,
            1000000000,
            64,
            \App\Shared\Application\DTO\DeploymentUnit::Control,
            [0],
            'historical-ipc',
            mediaMounts: [['source' => $source, 'target' => '/media/movies', 'readOnly' => true, 'role' => 'source']],
            sourceMountPolicy: 'local-development',
        );
        $original = $recipe->fingerprint();
        file_put_contents($source . '/unrelated-media', 'media is not an input to retirement');
        self::assertSame($original, $recipe->fingerprint());
        unlink($source . '/unrelated-media');
        \Baander\Tooling\Tests\Historical\DeploymentContainerRecipe::verifySourceDirectory($source);
        \Baander\Tooling\Tests\Historical\DeploymentContainerRecipe::verifySourceDirectory(dirname($source));
        self::assertSame($original, $recipe->fingerprint());
        self::assertSame([], array_values(array_diff(scandir($source), ['.', '..'])));
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
    private function preparationRunner(bool $reject = false): WorkerCommandExecutor
    {
        return $this->runner(static function (array $arguments) use ($reject): string|\Baander\Tooling\Process\ProcessResult {
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
        self::assertSame([], glob($this->root . '/resource-*.json'));
        self::assertSame([], glob($this->instance['workers']['directory'] . '/*-resources.json'));
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

    public function testProductionValidationFailureRetainsPreparingOwnership(): void
    {
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', $this->environment());
        try {
            new Workers($this->store, $this->preparationRunner(true))->prepare($this->instance, $this->release);
            self::fail('Rejected manifest accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('overlapping media paths', $error->getMessage());
        }
        self::assertSame('preparing', $this->instance['workers']['phase']);
        self::assertEquals($this->instance['workers'], $this->store->load('persistent')['workers']);
        self::assertStringContainsString('-ipc-', $this->instance['workers']['ipc_volume']);
        self::assertSame([], glob($this->root . '/resource-*.json'));
        self::assertSame([], glob($this->instance['workers']['directory'] . '/*-resources.json'));
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
        $runner = $this->runner(static function (array $arguments) use ($containers): string {
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
        $environment = getenv();
        $imageReference = $environment['BAANDER_TEST_OPERATOR_IMAGE'] ?? '';
        if ($imageReference === '') {
            self::markTestSkipped('Requires BAANDER_TEST_OPERATOR_IMAGE naming a qualified local operator image.');
        }
        $borrowedNetwork = $environment['BAANDER_TEST_WORKER_NETWORK'] ?? '';
        if ($topology === 'invoke' && (empty($environment['BAANDER_TEST_WORKER_DATABASE_URL']) || $borrowedNetwork === '')) {
            self::markTestSkipped('Requires disposable migrated PostgreSQL via BAANDER_TEST_WORKER_DATABASE_URL and BAANDER_TEST_WORKER_NETWORK.');
        }
        $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::forRepository(dirname(__DIR__, 3), $environment);
        $commandEnvironment = \Baander\Tooling\Development\Environment::clean($environment);
        $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded(
            $configuration->budgets->seconds('workerOperator'),
            $commandEnvironment,
        ));
        try {
            $target = new \Baander\Tooling\Docker\TargetResolver($executor, $environment)->resolve($configuration->docker, $this->root . '/transport');
            $docker = new \Baander\Tooling\Docker\Client($executor, $target, $commandEnvironment);
            $inspection = json_decode($docker->run(['image', 'inspect', $imageReference])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            $image = $inspection['Id'];
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $image);
            $layout = \Baander\Tooling\Configuration\ApplicationLayout::fromImage($inspection);
            $project = 'baander-worker-parser-' . bin2hex(random_bytes(6));
            $this->instance['project'] = $project;
            $this->instance['hostname'] = 'workers.baander.app';
            $this->instance['network'] = $borrowedNetwork === '' ? $project : $borrowedNetwork;
            $this->instance['docker'] = $target->toArray();
            $this->release['layout'] = $layout->toArray();
            $this->release['target'] = $target->toArray();
            $this->release['effective']['budgets'] = $configuration->budgets->toArray();
            foreach ($this->release['images'] as &$role) {
                $role['resolvedId'] = $image;
                $role['daemonId'] = $target->daemonId;
                $role['reference'] = $imageReference;
            }
            unset($role);
            $applicationEnvironment = $this->environment();
            $applicationEnvironment['DATABASE_URL'] = $environment['BAANDER_TEST_WORKER_DATABASE_URL'] ?? $applicationEnvironment['DATABASE_URL'];
            AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', $applicationEnvironment);
            if (in_array($topology, ['explicit', 'replace', 'overlap'], true)) {
                mkdir($this->root . '/library');
                $this->instance['media'] = [['source' => $this->root . '/library', 'target' => match ($topology) {
                    'explicit' => '/media/library', 'replace' => '/storage/media', default => '/storage/media/nested',
                }]];
            }
            if ($topology === 'derived') {
                $this->instance['state_mounts']['storage'] = ['type' => 'bind', 'source' => $this->root, 'target' => $layout->path('storage')];
            }
            $ledger = new \Baander\Tooling\Resources\ResourceLedger($this->root . '/qualification-resources.json', $docker, $commandEnvironment);
            $recordingExecutor = new class ($executor, $ledger, 3 + count($target->transportArguments)) implements \Baander\Tooling\Process\CommandExecutor {
                public function __construct(
                    private readonly \Baander\Tooling\Process\CommandExecutor $executor,
                    private readonly \Baander\Tooling\Resources\ResourceLedger $ledger,
                    private readonly int $prefixLength,
                ) {}
                public function run(
                    \Baander\Tooling\Process\ProcessSpec $command,
                    \Baander\Tooling\Process\CompletionPolicy $policy = new \Baander\Tooling\Process\CompletionPolicy(),
                ): \Baander\Tooling\Process\ProcessResult {
                    $arguments = array_slice($command->argv, $this->prefixLength);
                    if (array_slice($arguments, 0, 2) === ['volume', 'create']) {
                        $name = array_pop($arguments);
                        $id = $this->ledger->create(\Baander\Tooling\Resources\ResourceKind::Volume, $name, array_slice($arguments, 2));
                        return $policy->complete(new \Baander\Tooling\Process\ProcessResult(0, null, false, false, $id, '', 0), $command);
                    }
                    return $this->executor->run($command, $policy);
                }
                public function start(\Baander\Tooling\Process\ProcessSpec $command): \Baander\Tooling\Process\RunningProcess
                {
                    return $this->executor->start($command);
                }
                public function wait(
                    \Baander\Tooling\Process\RunningProcess $process,
                    \Baander\Tooling\Process\CompletionPolicy $policy = new \Baander\Tooling\Process\CompletionPolicy(),
                ): \Baander\Tooling\Process\ProcessResult {
                    return $this->executor->wait($process, $policy);
                }
                public function stop(\Baander\Tooling\Process\RunningProcess $process, int $signal = 15): \Baander\Tooling\Process\ProcessResult
                {
                    return $this->executor->stop($process, $signal);
                }
            };
            $ledger->scoped(function () use ($topology, $borrowedNetwork, $project, $ledger, $recordingExecutor, $commandEnvironment, $layout, $applicationEnvironment): void {
                if ($borrowedNetwork === '') {
                    $ledger->create(\Baander\Tooling\Resources\ResourceKind::Network, $project, ['--internal']);
                }
                $workers = new Workers($this->store, $recordingExecutor, new \Baander\Tooling\Development\Connections($recordingExecutor, $commandEnvironment));
                if (in_array($topology, ['overlap', 'derived'], true)) {
                    try {
                        $workers->prepare($this->instance, $this->release);
                        self::fail('Unsafe production topology accepted');
                    } catch (WorkflowError $error) {
                        self::assertStringContainsString($topology === 'overlap' ? 'overlapping media paths' : 'requires a named volume', $error->getMessage());
                    }
                    if ($topology === 'overlap') {
                        self::assertSame('preparing', $this->instance['workers']['phase']);
                        self::assertSame($this->instance['workers'], $this->store->load('persistent')['workers']);
                    } else {
                        self::assertArrayNotHasKey('workers', $this->instance);
                    }
                    return;
                }
                $workers->prepare($this->instance, $this->release);
                $directory = $this->instance['workers']['directory'];
                $manifest = AtomicFile::readJson($directory . '/manifest.json');
                $mounts = array_column($manifest['mediaMounts'], null, 'target');
                self::assertSame('source', $mounts['/storage/media']['role']);
                self::assertTrue($mounts['/storage/media']['readOnly']);
                foreach (['/storage/conversions', $layout->path('storage'), $layout->path('var/transcode_state')] as $path) {
                    self::assertSame('derived', $mounts[$path]['role']);
                    self::assertFalse($mounts[$path]['readOnly']);
                }
                if (in_array($topology, ['explicit', 'replace'], true)) {
                    self::assertSame($this->root . '/library', $mounts[$topology === 'explicit' ? '/media/library' : '/storage/media']['source']);
                }
                $credentials = AtomicFile::readJson($directory . '/credentials.json')['runtimeEnvironment'];
                foreach (['VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY', 'STREAM_URL_HMAC_SECRET'] as $key) {
                    self::assertSame($applicationEnvironment[$key], $credentials[$key]);
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
            }, $configuration->budgets->seconds('cleanup'));
        } catch (\Throwable $error) {
            $this->retainQualificationEvidence = file_exists($this->root . '/qualification-resources.json')
                || (glob($this->store->directory('persistent') . '/workers/*/*-resources.json') ?: []) !== [];
            if ($this->retainQualificationEvidence) {
                fwrite(STDERR, 'Retained qualification ownership and transport: ' . $this->root . "\n");
            }
            throw $error;
        } finally {
            $executor->close();
        }
    }
}
