<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\{ReleaseContract, RetainedState, StateStore, WorkflowError};
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentRetainedStateTest extends TestCase
{
    private string $root;
    private StateStore $store;
    private RetainedStateExecutor $executor;
    private const string READY = 'preview-ef7b4215ec1be5f0';
    private const string BLOCKED = 'preview-fd790a887b6899cb';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-retained-contract-' . bin2hex(random_bytes(8));
        PrivateDirectory::prepare($this->root);
        $this->store = new StateStore($this->root . '/state');
        $this->executor = new RetainedStateExecutor();
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @return array<string,string> */
    private function legacyTarget(): array
    {
        return ['executable' => '/bin/true', 'context' => 'retained-context', 'daemon_id' => 'retained-daemon'];
    }

    /** @return array<string,mixed> */
    private function release(string $name): array
    {
        $source = $this->root . '/' . $name;
        PrivateDirectory::prepare($source . '/bin');
        file_put_contents($source . '/Dockerfile', "FROM php:8.5\nWORKDIR /var/www/html\n");
        file_put_contents($source . '/bin/console', '<?php');
        $release = ['revision' => hash('sha1', $name), 'source' => $source, 'image_pins' => []];
        foreach (ReleaseContract::IMAGE_KEYS as $key) {
            $release[$key] = 'sha256:' . hash('sha256', $name . $key);
            $release['image_pins'][$key] = 'baander.app/' . $key . '@sha256:' . hash('sha256', $name . $key . 'pin');
        }
        return $release;
    }

    /** @return array<string,mixed> */
    private function record(bool $blocked = false): array
    {
        $id = $blocked ? self::BLOCKED : self::READY;
        $directory = $this->store->directory($id);
        PrivateDirectory::prepare($directory . '/checkpoints/retained');
        AtomicFile::write($directory . '/env.json', "{\"APP_SECRET\":\"fixture-identity\",\"AUTH_SPA_CLIENT_ID\":\"retained-client\"}\n");
        AtomicFile::write($directory . '/checkpoints/retained/checkpoint.json', "{\"verified\":true}\n");
        $libraries = $blocked ? [] : [
            ['slug' => 'movies', 'name' => 'Movies', 'type' => 'movies', 'source' => '/host/movies', 'target' => '/media/movies'],
            ['slug' => 'music', 'name' => 'Music', 'type' => 'music', 'source' => '/host/music', 'target' => '/media/music'],
        ];
        $record = [
            'id' => $id, 'kind' => 'preview', 'project' => 'retained-' . substr($id, 8),
            'repository' => 'retained-repository', 'worktree' => $this->root . '/missing-worktree',
            'hostname' => $id . '.baander.test', 'phase' => $blocked ? 'blocked' : 'ready',
            'initialization' => 'complete', 'docker' => $this->legacyTarget(),
            'prepared_release' => $this->release($id . '-prepared'),
            'libraries' => $libraries,
            'media' => array_map(static fn(array $library): array => ['source' => $library['source'], 'target' => $library['target']], $libraries),
            'postgres_image' => 'sha256:retained-postgres-service', 'redis_image' => 'sha256:retained-redis-service',
            'volumes' => ['database' => 'retained-database', 'storage' => 'retained-storage'],
            'workers' => ['manifest' => 'retained-worker-manifest'], 'operator' => ['container' => 'retained-operator'],
            'checkpoint' => ['directory' => $directory . '/checkpoints/retained'],
        ];
        if ($blocked) {
            $record += ['writers_stopped' => true, 'database_mutation_started' => true, 'database_mutation_confirmed' => true];
        } else {
            $record['release'] = $this->release($id . '-active');
        }
        $this->store->save($id, $record);
        return $this->store->load($id);
    }

    private function converter(): RetainedState
    {
        return new RetainedState($this->store, $this->executor, ['PATH' => '/bin:/usr/bin', 'HOME' => $this->root]);
    }

    /** @return iterable<string,array{bool}> */
    public static function legacyRecords(): iterable
    {
        yield 'ready active and prepared' => [false];
        yield 'blocked prepared only' => [true];
    }

    #[DataProvider('legacyRecords')]
    public function testActualLegacyRecordsConvertWithoutChangingRetainedIdentity(bool $blocked): void
    {
        $record = $this->record($blocked);
        $directory = $this->store->directory($record['id']);
        $original = file_get_contents($directory . '/state.json');
        $environment = file_get_contents($directory . '/env.json');
        $checkpoint = file_get_contents($directory . '/checkpoints/retained/checkpoint.json');
        $this->converter()->convert($record['id']);
        $saved = $this->store->load($record['id']);
        self::assertSame(1, $saved['version']);
        self::assertSame(1, $saved['docker']['version']);
        self::assertSame('retained-daemon', $saved['docker']['daemonId']);
        foreach (['libraries', 'media', 'postgres_image', 'redis_image', 'volumes', 'checkpoint', 'workers', 'operator'] as $field) {
            self::assertSame($record[$field], $saved[$field]);
        }
        self::assertSame($environment, file_get_contents($directory . '/env.json'));
        self::assertSame($checkpoint, file_get_contents($directory . '/checkpoints/retained/checkpoint.json'));
        $backups = glob($directory . '/state.json.pre-contract-*');
        self::assertCount(1, $backups);
        self::assertSame($original, file_get_contents($backups[0]));
        self::assertSame(0600, fileperms($backups[0]) & 0777);
        self::assertSame(!$blocked, isset($saved['release']));
        foreach ($blocked ? ['prepared_release'] : ['release', 'prepared_release'] as $field) {
            $release = ReleaseContract::fromArray($saved[$field])->toArray();
            self::assertEquals(['version' => 1, 'root' => '/var/www/html'], $release['layout']);
            self::assertSame($saved['docker'], $release['target']);
            self::assertSame($record[$field]['revision'], $release['revision']);
            self::assertSame($record[$field]['source'], $release['source']);
            self::assertSame($record['libraries'], $release['effective']['libraries']);
            self::assertSame($record['media'], $release['effective']['media']);
            self::assertArrayHasKey($directory . '/env.json', $release['inputs']);
            foreach (ReleaseContract::IMAGE_KEYS as $key) {
                self::assertArrayNotHasKey($key, $release);
                self::assertSame($record[$field][$key], $release['images'][$key]['resolvedId']);
                self::assertSame($record[$field]['image_pins'][$key], $release['images'][$key]['reference']);
                self::assertSame('retained-daemon', $release['images'][$key]['daemonId']);
            }
        }
        if (!$blocked) {
            self::assertNotSame($saved['release']['images'], $saved['prepared_release']['images']);
        }
        self::assertFileDoesNotExist($record['prepared_release']['source'] . '/packages/baander-tooling/resources/application-layout.json');
    }

    /** @return iterable<string,array{string,bool}> */
    public static function historicalVariableLayouts(): iterable
    {
        yield 'literal environment' => ["ENV APP_HOME=/var/www/html \\\n    APP_ENV=prod\nWORKDIR \${APP_HOME}\n", true];
        yield 'unresolved environment' => ["ARG APP_HOME\nWORKDIR \${APP_HOME}\n", false];
        yield 'different environment' => ["ENV APP_HOME=/srv/baander\nWORKDIR \${APP_HOME}\n", false];
        yield 'overridden environment' => ["ENV APP_HOME=/var/www/html\nENV APP_HOME=/srv/baander\nWORKDIR \${APP_HOME}\n", false];
        yield 'legacy syntax override' => ["ENV APP_HOME=/var/www/html\nENV APP_HOME /srv/baander\nWORKDIR \${APP_HOME}\n", false];
        yield 'environment declared too late' => ["WORKDIR \${APP_HOME}\nENV APP_HOME=/var/www/html\n", false];
        yield 'environment from unrelated stage' => ["ENV APP_HOME=/var/www/html\nFROM php:8.5\nWORKDIR \${APP_HOME}\n", false];
    }

    #[DataProvider('historicalVariableLayouts')]
    public function testHistoricalVariableLayoutRequiresLiteralUnambiguousSource(string $instructions, bool $supported): void
    {
        $record = $this->record(true);
        file_put_contents($record['prepared_release']['source'] . '/Dockerfile', "FROM php:8.5\n" . $instructions);
        $path = $this->store->directory($record['id']) . '/state.json';
        $original = file_get_contents($path);
        if (!$supported) {
            try {
                $this->converter()->convert($record['id']);
                self::fail('Unproven historical layout was accepted.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('Historical source does not prove', $error->getMessage());
                self::assertSame($original, file_get_contents($path));
            }
            return;
        }
        $this->converter()->convert($record['id']);
        self::assertSame('/var/www/html', $this->store->load($record['id'])['prepared_release']['layout']['root']);
        self::assertSame($original, file_get_contents(glob($path . '.pre-contract-*')[0]));
    }

    /** @return iterable<string,array{string}> */
    public static function unavailableEvidence(): iterable
    {
        yield 'daemon changed' => ['daemon'];
        yield 'image unavailable' => ['image'];
        yield 'historical source absent' => ['source'];
    }

    #[DataProvider('unavailableEvidence')]
    public function testFailedConversionPreservesOriginalBytesAndCheckpoints(string $failure): void
    {
        $record = $this->record(true);
        $directory = $this->store->directory($record['id']);
        $original = file_get_contents($directory . '/state.json');
        $environment = file_get_contents($directory . '/env.json');
        $checkpoint = file_get_contents($directory . '/checkpoints/retained/checkpoint.json');
        if ($failure === 'daemon') {
            $this->executor->daemonId = 'different-daemon';
        } elseif ($failure === 'image') {
            $this->executor->imagesAvailable = false;
        } else {
            unlink($record['prepared_release']['source'] . '/Dockerfile');
        }
        try {
            $this->converter()->convert($record['id']);
            self::fail('Unsupported retained activation was accepted.');
        } catch (\RuntimeException $error) {
            self::assertNotSame('', $error->getMessage());
            self::assertSame($original, file_get_contents($directory . '/state.json'));
            self::assertSame($environment, file_get_contents($directory . '/env.json'));
            self::assertSame($checkpoint, file_get_contents($directory . '/checkpoints/retained/checkpoint.json'));
        }
    }

    public function testCleanupCanCaptureTransportWithoutHistoricalImagesOrSource(): void
    {
        $record = $this->record(true);
        $directory = $this->store->directory($record['id']);
        $original = file_get_contents($directory . '/state.json');
        $this->executor->imagesAvailable = false;
        unlink($record['prepared_release']['source'] . '/Dockerfile');
        $this->converter()->convert($record['id'], activation: false);
        $saved = $this->store->load($record['id']);
        self::assertSame(1, $saved['docker']['version']);
        self::assertEquals($record['prepared_release'], $saved['prepared_release']);
        self::assertSame($original, file_get_contents(glob($directory . '/state.json.pre-contract-*')[0]));
        self::assertFalse(array_any($this->executor->commands, static fn($command): bool => in_array('image', $command->argv, true)));
    }

    public function testFutureSchemaRemainsInspectableButCannotBeSavedOrActivated(): void
    {
        $record = $this->record(true);
        $record['version'] = 99;
        $path = $this->store->directory($record['id']) . '/state.json';
        AtomicFile::writeJson($path, $record);
        $original = file_get_contents($path);
        self::assertSame(99, $this->store->load($record['id'])['version']);
        foreach ([fn() => $this->store->save($record['id'], $record), fn() => $this->converter()->convert($record['id'])] as $operation) {
            try {
                $operation();
                self::fail('Future schema was overwritten or activated.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('Unsupported saved instance version', $error->getMessage());
                self::assertSame($original, file_get_contents($path));
            }
        }
        self::assertSame([], $this->executor->commands);
    }

    public function testUnleasedLegacyDispatcherBlocksBeforeDockerAndPreservesOriginal(): void
    {
        $record = $this->record(true);
        $path = $this->store->directory($record['id']) . '/state.json';
        $original = file_get_contents($path);
        $dispatcher = $this->store->root . '/controller/scripts/dev-environment.php';
        PrivateDirectory::prepare(dirname($dispatcher));
        AtomicFile::write($dispatcher, '<?php /* retained unleased dispatcher */');
        try {
            $this->converter()->convert($record['id']);
            self::fail('Unleased dispatcher was treated as drained.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('unleased readers', $error->getMessage());
            self::assertSame($original, file_get_contents($path));
            self::assertFileExists($dispatcher);
            self::assertSame([], $this->executor->commands);
            self::assertSame([], glob($path . '.pre-contract-*'));
        }
    }

    public function testLegacyGatewayStillConvertsWhenInstanceAlreadyHasCurrentContract(): void
    {
        $record = $this->record(true);
        $this->converter()->convert($record['id']);
        $instancePath = $this->store->directory($record['id']) . '/state.json';
        $instanceBytes = file_get_contents($instancePath);
        $gateway = ['compose_file' => $this->root . '/gateway-compose.json', 'docker' => $this->legacyTarget(),
            'gateway' => ['ipv4_address' => '172.29.20.2'], 'nginx_image' => 'sha256:retained-gateway', 'project' => 'retained-gateway'];
        $path = $this->store->root . '/gateway/state.json';
        AtomicFile::writeJson($path, $gateway);
        $original = file_get_contents($path);
        $this->converter()->convert($record['id']);
        self::assertSame(1, AtomicFile::readJson($path)['docker']['version'] ?? null);
        self::assertSame($original, file_get_contents(glob($path . '.pre-contract-*')[0]));
        self::assertSame($instanceBytes, file_get_contents($instancePath));
    }

    public function testSharedLegacyGatewayConvertsWithItsOwnOriginalBackup(): void
    {
        $record = $this->record(true);
        $gateway = ['compose_file' => $this->root . '/gateway-compose.json', 'docker' => $this->legacyTarget(),
            'gateway' => ['ipv4_address' => '172.29.20.2'], 'nginx_image' => 'sha256:retained-gateway', 'project' => 'retained-gateway'];
        $path = $this->store->root . '/gateway/state.json';
        AtomicFile::writeJson($path, $gateway);
        $original = file_get_contents($path);
        $this->converter()->convert($record['id']);
        $saved = AtomicFile::readJson($path);
        self::assertSame(1, $saved['version']);
        self::assertSame(1, $saved['docker']['version']);
        unset($saved['version'], $saved['docker']);
        unset($gateway['docker']);
        self::assertSame($gateway, $saved);
        self::assertSame($original, file_get_contents(glob($path . '.pre-contract-*')[0]));
    }
}
