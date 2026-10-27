<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Bootstrap;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Fixtures/dev-data.php';

final class DevelopmentBootstrapTest extends DevDataTestCase
{
    /** @return array<string,string> */
    private function environment(): array
    {
        return [
            'APP_SECRET' => 'original-private-secret',
            'STREAM_URL_HMAC_SECRET' => 'original-stream',
            'VAPID_PRIVATE_KEY' => 'original-vapid-private',
            'VAPID_PUBLIC_KEY' => 'original-vapid-public',
            'AUTH_SPA_CLIENT_ID' => 'original-client',
            'DB_PASSWORD' => 'original-password',
            'DB_USERNAME' => 'baander',
            'DB_DATABASE' => 'baander',
            'MEDIA_STORAGE_PATH' => '/storage/media',
            'CONVERT_STORAGE_PATH' => '/storage/conversions',
        ];
    }
    /** @return array<string,mixed> */
    private function adoption(bool $frontend = false): array
    {
        return [
            'containers' => ['postgres' => 'exact-postgres', 'redis' => 'exact-redis', 'app' => 'exact-app'] + ($frontend ? ['nginx' => 'exact-nginx'] : []),
            'worker_manifests' => [],
        ];
    }
    /** @param array<string,string>|null $appEnvironment
     * @param array<string,mixed>|null $observation
     * @param list<array<string,string>> $extraMounts
     */
    private function inventoryRunner(?array $appEnvironment = null, ?array $observation = null, array $extraMounts = []): DevDataRunner
    {
        $environment = $this->environment();
        return new DevDataRunner(static function (array $arguments) use ($environment, $appEnvironment, $observation, $extraMounts): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (in_array('inspect', $arguments, true) && in_array('--format', $arguments, true)) {
                return '0';
            }
            if (in_array('inspect', $arguments, true) && !in_array('volume', $arguments, true)) {
                $identifier = end($arguments);
                $mounts = [];
                $entries = [];
                if ($identifier === 'exact-postgres') {
                    $mounts = [['Type' => 'volume', 'Name' => 'existing-database', 'Destination' => '/var/lib/postgresql/data']];
                    $entries = [
                        'PGDATA=/var/lib/postgresql/data/baanderdev',
                        'POSTGRES_PASSWORD=' . $environment['DB_PASSWORD'],
                        'POSTGRES_USER=baander',
                        'POSTGRES_DB=baander',
                    ];
                } elseif ($identifier === 'exact-redis') {
                    $mounts = [['Type' => 'volume', 'Name' => 'existing-redis', 'Destination' => '/data']];
                } else {
                    $mounts = [
                        ['Type' => 'bind', 'Source' => '/original/checkout', 'Destination' => '/var/www/html'],
                        ['Type' => 'bind', 'Source' => '/original/library', 'Destination' => '/storage/media'],
                        ['Type' => 'bind', 'Source' => '/original/conversions', 'Destination' => '/storage/conversions'],
                        ...$extraMounts,
                    ];
                    foreach ($appEnvironment ?? $environment as $key => $value) {
                        $entries[] = $key . '=' . $value;
                    }
                }
                return json_encode(
                    [[
                        'Id' => $identifier,
                        'Image' => 'existing-image',
                        'Config' => ['Env' => $entries],
                        'Mounts' => $mounts,
                        'State' => ['Running' => false],
                    ]],
                    JSON_THROW_ON_ERROR,
                );
            }
            if (array_any($arguments, static fn(string $argument): bool => str_contains($argument, 'bootEnv'))) {
                return json_encode($observation, JSON_THROW_ON_ERROR);
            }
            return '';
        });
    }

    public function testConfigurePreservesGeneratedIdentityOnResumeAndTargetsManagedServices(): void
    {
        $bootstrap = new Bootstrap($this->store, new Runner());
        $configured = $bootstrap->configure($this->instance);
        $path = $this->store->directory('persistent') . '/env.json';
        $before = file_get_contents($path);
        $bootstrap->configure($configured);
        self::assertSame($before, file_get_contents($path));
        $environment = AtomicFile::readJson($path);
        self::assertSame('prod', $environment['APP_ENV']);
        self::assertStringContainsString('@database:5432/baander?', $environment['DATABASE_URL']);
        self::assertSame('/storage/media', $environment['MEDIA_STORAGE_PATH']);
        self::assertSame(87, strlen($environment['VAPID_PUBLIC_KEY']));
        self::assertSame(43, strlen($environment['VAPID_PRIVATE_KEY']));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testSavedIdentityRejectsDifferentExplicitEnvironment(): void
    {
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', ['APP_SECRET' => 'original']);
        $this->expectExceptionMessage('Saved instance identity differs');
        new Bootstrap($this->store, new Runner())->configure($this->instance, ['APP_SECRET' => 'different']);
    }

    public function testUnknownAdoptionRoleIsRejectedBeforeContainerInspection(): void
    {
        $runner = $this->inventoryRunner();
        try {
            new Bootstrap($this->store, $runner)->inventory(
                $this->instance,
                ['containers' => ['postgres' => 'p', 'redis' => 'r', 'app' => 'a', 'unexpected' => 'x']],
                [],
            );
            self::fail('Unknown role accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('exact postgres', $error->getMessage());
            self::assertCount(1, $runner->calls);
        }
    }

    public function testExplicitFrontendAndMatchingProcessIdentityAreInventoried(): void
    {
        $runner = $this->inventoryRunner();
        $configured = new Bootstrap($this->store, $runner)->configure($this->instance, $this->environment(), $this->adoption(true));
        self::assertSame('adopted', $configured['initialization']);
        self::assertSame('exact-nginx', $configured['adoption_inventory']['nginx']['Id']);
        self::assertEquals($this->environment(), AtomicFile::readJson($this->store->directory('persistent') . '/env.json'));
        self::assertStringNotContainsString('bootEnv', json_encode($runner->calls, JSON_THROW_ON_ERROR));
        self::assertSame('/original/checkout/storage', $configured['state_mounts']['storage']['source']);
        self::assertTrue($configured['state_mounts']['media']['read_only']);
        self::assertSame('/original/library', $configured['state_mounts']['media']['source']);
    }

    public function testWrongSecretRefusesWithoutPersistingOrStoppingAndDoesNotDiscloseSecrets(): void
    {
        $runner = $this->inventoryRunner();
        $environment = array_replace($this->environment(), ['APP_SECRET' => 'wrong-private-secret']);
        try {
            new Bootstrap($this->store, $runner)->configure($this->instance, $environment, $this->adoption());
            self::fail('Mismatched identity accepted');
        } catch (WorkflowError $error) {
            self::assertStringNotContainsString($environment['APP_SECRET'], $error->getMessage());
            self::assertStringNotContainsString($this->environment()['APP_SECRET'], $error->getMessage());
        }
        self::assertFileDoesNotExist($this->store->directory('persistent') . '/env.json');
        self::assertFalse(array_any($runner->calls, static fn(array $call): bool => in_array('stop', $call['arguments'], true)));
        self::assertStringNotContainsString('bootEnv', json_encode($runner->calls, JSON_THROW_ON_ERROR));
    }

    public function testDotenvDigestObservationCompletesMissingProcessEnvironment(): void
    {
        $digests = [];
        foreach (['APP_SECRET', 'STREAM_URL_HMAC_SECRET', 'VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY'] as $key) {
            $digests[$key] = hash('sha256', $this->environment()[$key]);
        }
        $runner = $this->inventoryRunner([], $digests);
        new Bootstrap($this->store, $runner)->configure($this->instance, $this->environment(), $this->adoption());
        $probes = array_values(array_filter(
            $runner->calls,
            static fn(array $call): bool => str_contains(implode(' ', $call['arguments']), 'bootEnv'),
        ));
        self::assertCount(1, $probes);
        self::assertContains('exact-app', $probes[0]['arguments']);
        self::assertStringNotContainsString('bin/console', implode(' ', $probes[0]['arguments']));
        foreach (array_keys($digests) as $key) {
            self::assertStringNotContainsString($this->environment()[$key], implode(' ', $probes[0]['arguments']));
        }
    }

    public function testUnverifiableIdentityRefusesWithoutPersistence(): void
    {
        try {
            new Bootstrap($this->store, $this->inventoryRunner([], ['APP_SECRET' => null]))->configure($this->instance, $this->environment(), $this->adoption());
            self::fail('Incomplete digests accepted');
        } catch (WorkflowError) {
            self::assertFileDoesNotExist($this->store->directory('persistent') . '/env.json');
        }
    }

    /** @return iterable<string,array{string}> */
    public static function nestedPaths(): iterable
    {
        yield 'media' => ['MEDIA_STORAGE_PATH'];
        yield 'conversions' => ['CONVERT_STORAGE_PATH'];
    }
    #[DataProvider('nestedPaths')]
    public function testNestedWritablePathsAreRefusedBeforePersistence(string $key): void
    {
        $this->expectExceptionMessage('overlaps writable storage');
        new Bootstrap($this->store, $this->inventoryRunner())->configure(
            $this->instance,
            array_replace($this->environment(), [$key => '/var/www/html/storage/nested']),
            $this->adoption(),
        );
    }

    public function testTransferRequiresVerifiedCheckpoint(): void
    {
        $this->expectExceptionMessage('verified checkpoint');
        new Bootstrap($this->store, $this->inventoryRunner())->transfer($this->instance + ['writers_stopped' => true], $this->release, ['verified' => false]);
    }

    public function testTransferCopiesWritableArchivesWithoutHostBindsAndRetainsSourceVolumes(): void
    {
        $runner = $this->inventoryRunner();
        $bootstrap = new Bootstrap($this->store, $runner);
        $configured = $bootstrap->configure($this->instance, $this->environment(), $this->adoption());
        $configured['writers_stopped'] = true;
        $transferred = $bootstrap->transfer($configured, $this->release, ['verified' => true, 'directory' => $this->root . '/checkpoint']);
        self::assertSame($configured['state_mounts']['media'], $transferred['state_mounts']['media']);
        self::assertSame('existing-redis', $transferred['volumes']['redis']);
        self::assertSame('existing-database', $transferred['volumes']['database']);
        self::assertSame('volume', $transferred['state_mounts']['storage']['type']);
        $copies = array_values(array_filter($runner->calls, static fn(array $call): bool => in_array('cp', $call['arguments'], true)));
        self::assertTrue(array_any(
            $copies,
            static fn(array $call): bool => str_ends_with($call['arguments'][count($call['arguments']) - 2], '/storage.tar'),
        ));
        self::assertTrue(array_any(
            $copies,
            static fn(array $call): bool => str_ends_with($call['arguments'][count($call['arguments']) - 2], '/transcode_state.tar'),
        ));
        self::assertFalse(array_any(
            $copies,
            static fn(array $call): bool => str_ends_with($call['arguments'][count($call['arguments']) - 2], '/media.tar'),
        ));
        foreach ($runner->calls as $call) {
            self::assertFalse(array_any(
                $call['arguments'],
                static fn(string $argument): bool => str_contains($argument, 'type=bind') && str_contains($argument, 'checkpoint'),
            ));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function transcodeMounts(): iterable
    {
        yield 'volume' => ['volume'];
        yield 'bind' => ['bind'];
    }
    #[DataProvider('transcodeMounts')]
    public function testSeparateTranscodeMountIsInventoriedAndPreservedDuringTransfer(string $kind): void
    {
        $runner = $this->inventoryRunner(extraMounts: [[
            'Type' => $kind,
            'Destination' => '/var/www/html/var/transcode_state',
            'Name' => 'original-transcode',
            'Source' => '/original/transcode',
        ]]);
        $bootstrap = new Bootstrap($this->store, $runner);
        $configured = $bootstrap->inventory($this->instance, $this->adoption(), $this->environment());
        self::assertSame($kind, $configured['state_mounts']['transcode_state']['type']);
        $configured['writers_stopped'] = true;
        $transferred = $bootstrap->transfer($configured, $this->release, ['verified' => true, 'directory' => $this->root . '/checkpoint']);
        $copies = array_filter(
            $runner->calls,
            static fn(array $call): bool => in_array('cp', $call['arguments'], true) && str_ends_with($call['arguments'][count($call['arguments']) - 2], '/transcode_state.tar'),
        );
        self::assertCount($kind === 'volume' ? 0 : 1, $copies);
        self::assertSame('volume', $transferred['state_mounts']['transcode_state']['type']);
        if ($kind === 'volume') {
            self::assertSame('original-transcode', $transferred['volumes']['transcode_state']);
        }
    }

    public function testFreshSetupPersistsMutationBoundaryBeforeCommandAndRetainsFailure(): void
    {
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', ['APP_SECRET' => 'fixture']);
        $store = $this->store;
        $runner = new DevDataRunner(static function (array $arguments) use ($store): string {
            if (in_array('app:dev:setup', $arguments, true)) {
                self::assertTrue($store->load('persistent')['database_mutation_started']);
                throw new WorkflowError('setup interrupted');
            }
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            return str_contains(implode(' ', $arguments), 'psql') ? '{"versions":[],"objects":[]}' : 'container';
        });
        try {
            new Bootstrap($store, $runner)->initialize($this->instance, $this->release);
            self::fail('Interrupted setup accepted');
        } catch (WorkflowError $error) {
            self::assertSame('setup interrupted', $error->getMessage());
        }
        self::assertTrue($store->load('persistent')['database_mutation_started']);
        self::assertSame('blocked', $store->load('persistent')['phase']);
    }
}
