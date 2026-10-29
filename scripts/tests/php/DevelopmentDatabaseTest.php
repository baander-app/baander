<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Database;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Fixtures/dev-data.php';

final class DevelopmentDatabaseTest extends DevDataTestCase
{
    /** @param array<string,mixed> $catalog
     * @param array<string,mixed> $flags
     */
    private function resourceDatabase(array $catalog, array $flags = []): Database
    {
        $configuration = $this->root . '/database-fixture.json';
        AtomicFile::writeJson($configuration, ['root' => $this->root, 'catalog' => $catalog, ...$flags]);
        $executable = $this->root . '/database-docker';
        file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\n\$_SERVER['argv'] = [\$_SERVER['argv'][0], "
            . var_export($configuration, true) . ", ...array_slice(\$_SERVER['argv'], 1)];\nrequire "
            . var_export(__DIR__ . '/Fixtures/development-database-docker.php', true) . ";\n");
        chmod($executable, 0700);
        $this->instance['docker'] = new Target($executable, 'unix:///tmp/baander.app-database-fixture.sock', 'daemon', null, null)->toArray();
        $environmentPath = $this->store->directory('persistent') . '/env.json';
        if (!is_file($environmentPath)) {
            AtomicFile::writeJson($environmentPath, ['DB_USERNAME' => 'baander', 'DB_DATABASE' => 'baander',
                'DB_PASSWORD' => 'private-password', 'APP_SECRET' => 'private-secret']);
        }
        return new Database($this->store, new EngineExecutor(ExecutionContext::bounded(30, Environment::clean())));
    }

    /** @return list<list<string>> */
    private function resourceCommands(): array
    {
        return array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->root . '/database-calls.jsonl', FILE_IGNORE_NEW_LINES),
        );
    }

    public function testEmptyCatalogCanInitializeWithoutApplicationKernel(): void
    {
        $runner = $this->catalogRunner(['versions' => [], 'objects' => [], 'server_version' => 180004]);
        $catalog = new Database($this->store, $runner)->inspect($this->instance, $this->release);
        self::assertTrue($catalog['fresh']);
        self::assertCount(2, $catalog['pending']);
        self::assertStringNotContainsString('bin/console', json_encode($runner->calls, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('BEGIN READ ONLY;', $runner->calls[2]['input']);
        self::assertStringContainsString("statement_timeout = '15s'", $runner->calls[2]['input']);
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidCatalogs(): iterable
    {
        yield 'populated unversioned' => [['versions' => [], 'objects' => ['public.users']], 'unversioned'];
        yield 'former' => [['versions' => ['DoctrineMigrations\\Version20240101000000']], 'migration history'];
        yield 'hole' => [['versions' => ['DoctrineMigrations\\Version20261017130000']], 'migration history'];
        yield 'ahead' => [
            ['versions' => [
                'DoctrineMigrations\\Version20261017000000',
                'DoctrineMigrations\\Version20261017130000',
                'DoctrineMigrations\\Version20270101000000',
            ]],
            'migration history',
        ];
        yield 'malformed' => [['versions' => [17]], 'Malformed'];
    }

    /** @param array<string,mixed> $catalog */
    #[DataProvider('invalidCatalogs')]
    public function testInvalidMigrationHistoryIsRefused(array $catalog, string $message): void
    {
        $this->expectExceptionMessage($message);
        new Database($this->store, $this->catalogRunner($catalog))->inspect($this->instance, $this->release);
    }

    public function testValidPrefixReportsPendingAndAppliedHashes(): void
    {
        $result = new Database(
            $this->store,
            $this->catalogRunner(['versions' => ['DoctrineMigrations\\Version20261017000000'], 'objects' => ['public.users']]),
        )->inspect($this->instance, $this->release);
        self::assertFalse($result['fresh']);
        self::assertSame(['DoctrineMigrations\\Version20261017130000'], $result['pending']);
        self::assertSame(['Version20261017000000.php' => hash('sha256', '<?php')], $result['migration_hashes']);
        $this->instance['migration_hashes'] = ['Version20261017000000.php' => 'changed'];
        $this->expectExceptionMessage('applied migration changed');
        new Database($this->store, $this->catalogRunner(['versions' => ['DoctrineMigrations\\Version20261017000000']]))->inspect($this->instance, $this->release);
    }

    public function testMalformedInventoryFailsBeforeDocker(): void
    {
        file_put_contents($this->release['source'] . '/migrations/Versioninvalid.php', '<?php');
        $runner = $this->catalogRunner([]);
        try {
            new Database($this->store, $runner)->inspect($this->instance, $this->release);
            self::fail('Malformed inventory accepted');
        } catch (WorkflowError) {
            self::assertSame([], $runner->calls);
        }
    }

    public function testCheckpointRequiresQuiescence(): void
    {
        $this->expectExceptionMessage('writers');
        new Database($this->store, $this->catalogRunner([]))->checkpoint($this->instance, $this->release);
    }

    public function testMigrationFailureDurablyRecordsBoundaryAndBlocksRecovery(): void
    {
        $store = $this->store;
        $database = $this->resourceDatabase(['versions' => ['DoctrineMigrations\\Version20261017000000', 'DoctrineMigrations\\Version20261017130000']], ['attachedFailure' => true]);
        try {
            $database->migrate($this->instance + ['writers_stopped' => true, 'release' => ['revision' => 'old']], $this->release);
            self::fail('Unconfirmed migration accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('fixture migration failed after an earlier commit', $error->getMessage());
            self::assertSame(17, $error->getCode());
        }
        $saved = $store->load('persistent');
        self::assertSame('blocked', $saved['phase']);
        self::assertSame('old', $saved['release']['revision']);
        self::assertStringNotContainsString('ready', $saved['failure']);
    }

    public function testPendingMigrationRequiresVerifiedMatchingRehearsal(): void
    {
        $this->instance['writers_stopped'] = true;
        $this->expectExceptionMessage('verified isolated restore');
        new Database($this->store, $this->catalogRunner(['versions' => ['DoctrineMigrations\\Version20261017000000']]))->migrate($this->instance, $this->release, ['verified' => true, 'target_revision' => 'different']);
    }

    public function testCheckpointTransfersArchivesThroughDockerAndStopsRedisBeforeCopy(): void
    {
        $this->instance['writers_stopped'] = true;
        AtomicFile::writeJson($this->store->directory('persistent') . '/env.json', ['APP_SECRET' => 'fixture']);
        $database = $this->resourceDatabase(['versions' => [], 'objects' => []]);
        $checkpoint = $database->checkpoint($this->instance, $this->release);
        self::assertFalse($checkpoint['verified']);
        self::assertCount(6, $checkpoint['state']);
        self::assertSame(hash('sha256', 'archive'), $checkpoint['archive_sha256']);
        $stopped = false;
        foreach ($this->resourceCommands() as $arguments) {
            if (in_array('stop', $arguments, true)) {
                $stopped = true;
            }
            if (in_array('create', $arguments, true)) {
                self::assertTrue($stopped);
            }
            self::assertStringNotContainsString('type=bind,src=' . $checkpoint['directory'], implode(' ', $arguments));
        }
        self::assertFileDoesNotExist($this->store->directory('persistent') . '/checkpoint-resources.json');
        self::assertSame([], glob($this->root . '/docker-resources/*.json'));
        self::assertSame(0600, fileperms($checkpoint['directory'] . '/database.dump') & 0777);
    }

    public function testRehearsalRefusesChangedArchiveBeforeCreatingResources(): void
    {
        file_put_contents($this->root . '/database.dump', 'changed');
        $runner = $this->catalogRunner([]);
        $this->expectExceptionMessage('archive changed');
        new Database($this->store, $runner)->rehearse(
            $this->instance,
            $this->release,
            ['directory' => $this->root, 'archive_sha256' => hash('sha256', 'original')],
        );
    }

    public function testUnresolvedCheckpointReceiptBlocksBeforeAnotherCheckpoint(): void
    {
        $path = $this->store->directory('persistent') . '/checkpoint-resources.json';
        AtomicFile::write($path, 'retained unresolved receipt');
        $runner = $this->catalogRunner([]);
        try {
            new Database($this->store, $runner)->checkpoint($this->instance + ['writers_stopped' => true], $this->release);
            self::fail('A new checkpoint bypassed unresolved resource ownership.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString($path, $error->getMessage());
            self::assertSame('retained unresolved receipt', file_get_contents($path));
            self::assertSame([], $runner->calls);
        }
    }

    public function testUnresolvedRehearsalReceiptBlocksBeforeAnyDockerOperation(): void
    {
        $path = $this->root . '/rehearsal-resources.json';
        AtomicFile::write($path, 'retained unresolved receipt');
        $runner = $this->catalogRunner([]);
        try {
            new Database($this->store, $runner)->rehearse($this->instance, $this->release, ['directory' => $this->root]);
            self::fail('A new rehearsal bypassed unresolved resource ownership.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString($path, $error->getMessage());
            self::assertSame('retained unresolved receipt', file_get_contents($path));
            self::assertSame([], $runner->calls);
        }
    }

    public function testUnresolvedRehearsalCleanupRetainsArchivesAndBlocksVerification(): void
    {
        AtomicFile::write($this->root . '/database.dump', 'retained-database-archive');
        AtomicFile::write($this->root . '/secrets.tar', 'retained-secrets-archive');
        $catalog = ['versions' => [], 'objects' => [], 'extensions' => [], 'owner' => 'baander'];
        $database = $this->resourceDatabase($catalog);
        touch($this->root . '/deny-removal');
        try {
            $database->rehearse($this->instance, $this->release, ['directory' => $this->root,
                'archive_sha256' => hash('sha256', 'retained-database-archive'), 'target_revision' => 'target',
                'postgres_image' => 'saved-postgres', 'catalog' => $catalog]);
            self::fail('Unresolved cleanup was accepted as verified rehearsal.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('fixture removal denied', $error->getMessage());
        }
        self::assertFileExists($this->root . '/rehearsal-resources.json');
        self::assertFileDoesNotExist($this->root . '/checkpoint.json');
        self::assertSame('retained-database-archive', file_get_contents($this->root . '/database.dump'));
        self::assertSame('retained-secrets-archive', file_get_contents($this->root . '/secrets.tar'));
        $remaining = array_map(AtomicFile::readJson(...), glob($this->root . '/docker-resources/*.json'));
        self::assertFalse(array_any($remaining, static fn(array $resource): bool => str_contains($resource['Name'], '-app-1')));
    }

    /** @return iterable<string,array{bool}> */
    public static function rehearsalOutcomes(): iterable
    {
        yield 'success twice' => [false];
        yield 'migration failure' => [true];
    }

    #[DataProvider('rehearsalOutcomes')]
    public function testIsolatedRehearsalUsesDockerArchivesAndCleansOwnedResources(bool $failure): void
    {
        file_put_contents($this->root . '/database.dump', 'database-archive');
        file_put_contents($this->root . '/secrets.tar', 'secrets-archive');
        AtomicFile::writeJson(
            $this->store->directory('persistent') . '/env.json',
            [
                'DB_USERNAME' => 'baander',
                'DB_DATABASE' => 'baander',
                'DB_PASSWORD' => 'private-password',
                'APP_SECRET' => 'private-secret',
            ],
        );
        $catalog = ['versions' => [], 'objects' => [], 'extensions' => ['plpgsql' => '1.0'], 'owner' => 'baander'];
        $checkpoint = [
            'directory' => $this->root,
            'archive_sha256' => hash('sha256', 'database-archive'),
            'target_revision' => 'target',
            'postgres_image' => 'recorded-postgres',
            'catalog' => $catalog,
        ];
        $database = $this->resourceDatabase($catalog, ['migrationFailure' => $failure]);
        try {
            $result = $database->rehearse($this->instance, $this->release, $checkpoint);
            self::assertFalse($failure, 'Failed migration was accepted');
            self::assertTrue($result['verified']);
            self::assertTrue(AtomicFile::readJson($this->root . '/checkpoint.json')['verified']);
        } catch (WorkflowError $error) {
            self::assertTrue($failure);
            self::assertStringContainsString('Isolated migration rehearsal failed', $error->getMessage());
            self::assertFileDoesNotExist($this->root . '/checkpoint.json');
        }
        $creates = array_values(array_filter(
            $this->resourceCommands(),
            static fn(array $arguments): bool => in_array('create', $arguments, true) && in_array('doctrine:migrations:migrate', $arguments, true),
        ));
        self::assertCount($failure ? 1 : 2, $creates);
        foreach ($creates as $arguments) {
            self::assertNotContains('--mount', $arguments);
            self::assertStringNotContainsString('private-password', implode(' ', $arguments));
            self::assertStringNotContainsString('private-secret', implode(' ', $arguments));
        }
        self::assertSame([], glob($this->root . '/docker-resources/*.json'));
        self::assertSame([], glob($this->root . '/rehearsal-*.json'));
        self::assertTrue(array_any($this->resourceCommands(), static fn(array $arguments): bool => array_slice($arguments, 0, 2) === ['network', 'rm']));
    }
}
