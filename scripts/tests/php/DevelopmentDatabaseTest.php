<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Database;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Fixtures/dev-data.php';

final class DevelopmentDatabaseTest extends DevDataTestCase
{
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
        file_put_contents($this->root . '/migrations/Versioninvalid.php', '<?php');
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
        $runner = new DevDataRunner(static function (array $arguments) use ($store): string {
            if (in_array('doctrine:migrations:migrate', $arguments, true)) {
                self::assertTrue($store->load('persistent')['database_mutation_started']);
                throw new WorkflowError('later migration failed after an earlier commit');
            }
            return in_array('info', $arguments, true) ? '{"ID":"daemon"}' : (str_contains(implode(' ', $arguments), 'psql') ? '{"versions":["DoctrineMigrations\\\\Version20261017000000","DoctrineMigrations\\\\Version20261017130000"],"writers":0}' : 'container');
        });
        try {
            new Database($store, $runner)->migrate($this->instance + ['writers_stopped' => true, 'release' => ['revision' => 'old']], $this->release);
            self::fail('Unconfirmed migration accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('later migration', $error->getMessage());
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
        $runner = new DevDataRunner(static function (array $arguments, ?string $input): string {
            $command = implode(' ', $arguments);
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (str_contains($command, 'psql')) {
                return '{"versions":[],"objects":[],"writers":0}';
            }
            if (str_contains($command, 'CONFIG GET dir')) {
                return "dir\n/data\n";
            }
            if (str_contains($command, 'redis-cli --raw SAVE')) {
                return "OK\n";
            }
            if (in_array('inspect', $arguments, true)) {
                return in_array('--format', $arguments, true) ? '0' : '[{"Id":"redis-container","State":{"Running":false}}]';
            }
            if (in_array('compose', $arguments, true)) {
                return end($arguments) === 'redis' ? 'redis-container' : 'postgres-container';
            }
            if (in_array('cp', $arguments, true)) {
                file_put_contents(end($arguments), 'archive');
            }
            return '';
        });
        $checkpoint = new Database($this->store, $runner)->checkpoint($this->instance, $this->release);
        self::assertFalse($checkpoint['verified']);
        self::assertCount(6, $checkpoint['state']);
        self::assertSame(hash('sha256', 'archive'), $checkpoint['archive_sha256']);
        $stopped = false;
        foreach ($runner->calls as $call) {
            if (in_array('stop', $call['arguments'], true)) {
                $stopped = true;
            }
            if (in_array('create', $call['arguments'], true)) {
                self::assertTrue($stopped);
            }
            self::assertStringNotContainsString('type=bind,src=' . $checkpoint['directory'], implode(' ', $call['arguments']));
        }
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
        $runner = new DevDataRunner(static function (array $arguments) use ($catalog, $failure): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (str_contains(implode(' ', $arguments), 'psql')) {
                return json_encode($catalog, JSON_THROW_ON_ERROR);
            }
            if (in_array('inspect', $arguments, true)) {
                return json_encode([['State' => ['ExitCode' => $failure ? 1 : 0]]], JSON_THROW_ON_ERROR);
            }
            return '';
        });
        try {
            $result = new Database($this->store, $runner)->rehearse($this->instance, $this->release, $checkpoint);
            self::assertFalse($failure, 'Failed migration was accepted');
            self::assertTrue($result['verified']);
            self::assertTrue(AtomicFile::readJson($this->root . '/checkpoint.json')['verified']);
        } catch (WorkflowError $error) {
            self::assertTrue($failure);
            self::assertStringContainsString('Isolated migration rehearsal failed', $error->getMessage());
            self::assertFileDoesNotExist($this->root . '/checkpoint.json');
        }
        $creates = array_values(array_filter(
            $runner->calls,
            static fn(array $call): bool => in_array('create', $call['arguments'], true) && in_array('doctrine:migrations:migrate', $call['arguments'], true),
        ));
        self::assertCount($failure ? 1 : 2, $creates);
        foreach ($creates as $call) {
            self::assertNotContains('--mount', $call['arguments']);
            self::assertStringNotContainsString('private-password', implode(' ', $call['arguments']));
            self::assertStringNotContainsString('private-secret', implode(' ', $call['arguments']));
        }
        $last = array_slice($runner->calls, -2);
        self::assertContains('--volumes', $last[0]['arguments']);
        self::assertSame(['network', 'rm'], array_slice($last[1]['arguments'], 3, 2));
    }
}
