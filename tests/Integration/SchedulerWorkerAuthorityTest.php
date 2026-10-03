<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerWorkerAuthority;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Committed PostgreSQL lease preflight; never runs scheduler effects or claims containment. */
final class SchedulerWorkerAuthorityTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    private string $databaseUrl;
    private DeploymentLease $lease;
    /** @var array<string, mixed> */
    private array $params;
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->databaseUrl = $url;
        $this->params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($this->params);
        $this->second = DriverManager::getConnection($this->params);
        $this->schema = 'scheduler_authority_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002210000.php';
        $migration = new \DoctrineMigrations\Version20261002210000($this->first, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->lease = new DeploymentLease('baander.app:scheduler-authority-test', str_repeat('a', 32), 1);
        $this->first->executeStatement("INSERT INTO worker_deployment_leases (namespace, owner_boot_id, epoch, state, expires_at) VALUES (:namespace, :boot, :epoch, 'active', clock_timestamp() + interval '1 hour')", ['namespace' => $this->lease->namespace, 'boot' => $this->lease->bootId, 'epoch' => $this->lease->epoch]);
        foreach (['BAANDER_WORKER_ID', 'BAANDER_WORKER_NAMESPACE', 'BAANDER_WORKER_BOOT_ID', 'BAANDER_WORKER_LEASE_EPOCH', 'PGOPTIONS'] as $name) {
            $this->environment[$name] = getenv($name);
        }
    }

    public function testActiveAuthorityIsReadOnlyAndTimeoutsAreTransactionLocal(): void
    {
        $before = $this->second->fetchAssociative('SELECT * FROM worker_deployment_leases');
        $adapter = new DoctrineSchedulerWorkerAuthority($this->first, $this->lease);
        $adapter->assertActive();
        $adapter->assertActive();
        self::assertSame($before, $this->second->fetchAssociative('SELECT * FROM worker_deployment_leases'));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertSame('0', $this->first->fetchOne('SHOW statement_timeout'));
        self::assertSame('0', $this->first->fetchOne('SHOW lock_timeout'));
    }

    public function testAbsentAuthorityDeniesBeforeOpeningTheConnection(): void
    {
        $connection = DriverManager::getConnection($this->params);
        try {
            (new DoctrineSchedulerWorkerAuthority($connection))->assertActive();
            self::fail('Missing authority must deny scheduler work.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler worker requires active deployment authority.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
    }

    #[DataProvider('incorrectTokens')]
    public function testNamespaceBootAndEpochMustMatchTheCommittedLease(string $namespace, string $boot, int $epoch): void
    {
        try {
            (new DoctrineSchedulerWorkerAuthority($this->first, new DeploymentLease($namespace, $boot, $epoch)))->assertActive();
            self::fail('A mismatched ownership token must deny scheduler work.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler worker requires active deployment authority.', $error->getMessage());
        }
        self::assertFalse($this->first->isConnected());
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_leases'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function incorrectTokens(): iterable
    {
        yield 'missing namespace' => ['baander.app:missing-scheduler', str_repeat('a', 32), 1];
        yield 'wrong boot' => ['baander.app:scheduler-authority-test', str_repeat('b', 32), 1];
        yield 'stale epoch' => ['baander.app:scheduler-authority-test', str_repeat('a', 32), 2];
    }

    public function testExpiryIsRecheckedAndNeverRenewed(): void
    {
        $adapter = new DoctrineSchedulerWorkerAuthority($this->first, $this->lease);
        $adapter->assertActive();
        $this->second->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - interval '1 second'");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scheduler worker requires active deployment authority.');
        $adapter->assertActive();
    }

    public function testContainmentAcknowledgementRevokesPreviouslyValidAuthority(): void
    {
        $adapter = new DoctrineSchedulerWorkerAuthority($this->first, $this->lease);
        $adapter->assertActive();
        // The fixture controller assumes predecessor cleanup was verified externally.
        $this->second->executeStatement("UPDATE worker_deployment_leases SET state = 'available'");
        $this->expectException(\RuntimeException::class);
        $adapter->assertActive();
    }

    public function testReplacementRevokesTheExactOldOwnerEvenWithActiveUnexpiredState(): void
    {
        $adapter = new DoctrineSchedulerWorkerAuthority($this->first, $this->lease);
        $adapter->assertActive();
        $this->second->executeStatement('UPDATE worker_deployment_leases SET owner_boot_id = :boot, epoch = epoch + 1', ['boot' => str_repeat('b', 32)]);
        $this->expectException(\RuntimeException::class);
        $adapter->assertActive();
    }

    public function testPreflightReadsCommittedStateWithoutTakingAnOwnershipLock(): void
    {
        $this->second->beginTransaction();
        try {
            $this->second->executeStatement("UPDATE worker_deployment_leases SET state = 'available'");
            (new DoctrineSchedulerWorkerAuthority($this->first, $this->lease, 500, 50))->assertActive();
            self::assertSame(0, $this->first->getTransactionNestingLevel());
        } finally {
            $this->second->rollBack();
        }
    }

    public function testAmbientTransactionIsRejectedWithoutClosingOrCommittingIt(): void
    {
        $this->first->beginTransaction();
        try {
            (new DoctrineSchedulerWorkerAuthority($this->first, $this->lease))->assertActive();
            self::fail('Shared ambient transactions must be rejected.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('dedicated idle autocommit connection', $error->getMessage());
            self::assertSame(1, $this->first->getTransactionNestingLevel());
            self::assertTrue($this->first->isConnected());
        } finally {
            $this->first->rollBack();
        }
    }

    public function testDisabledAutocommitIsRejectedWithoutChangingTheConnection(): void
    {
        $this->first->setAutoCommit(false);
        try {
            (new DoctrineSchedulerWorkerAuthority($this->first, $this->lease))->assertActive();
            self::fail('Disabled autocommit must be rejected.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('dedicated idle autocommit connection', $error->getMessage());
            self::assertFalse($this->first->isAutoCommit());
            self::assertTrue($this->first->isConnected());
        } finally {
            $this->first->setAutoCommit(true);
        }
    }

    public function testDatabaseFailureClosesTheDedicatedConnectionAndDeniesAuthority(): void
    {
        $this->second->executeStatement('DROP TABLE worker_deployment_leases');
        try {
            (new DoctrineSchedulerWorkerAuthority($this->first, $this->lease))->assertActive();
            self::fail('Missing authoritative storage must deny scheduler work.');
        } catch (DriverException $error) {
            self::assertSame('42P01', $error->getSQLState());
        }
        self::assertFalse($this->first->isConnected());
    }

    public function testTableContentionIsBoundedAndClosesTheDedicatedConnection(): void
    {
        $this->second->beginTransaction();
        try {
            $this->second->executeStatement('LOCK TABLE worker_deployment_leases IN ACCESS EXCLUSIVE MODE');
            try {
                (new DoctrineSchedulerWorkerAuthority($this->first, $this->lease, 500, 50))->assertActive();
                self::fail('A blocked authoritative read must fail closed.');
            } catch (DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
                self::assertFalse($this->first->isConnected());
            }
        } finally {
            $this->second->rollBack();
        }
    }

    #[DataProvider('wrongRoles')]
    public function testFactoryRejectsMissingOrDifferentWorkerRoleBeforeDatabaseAccess(string|false $role): void
    {
        $this->setWorkerContext();
        putenv($role === false ? 'BAANDER_WORKER_ID' : 'BAANDER_WORKER_ID=' . $role);
        $adapter = DoctrineSchedulerWorkerAuthority::fromDsn('postgresql://baander:test-only@127.0.0.1:1/scheduler_test');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scheduler worker requires active deployment authority.');
        $adapter->assertActive();
    }

    /** @return iterable<string, array{string|false}> */
    public static function wrongRoles(): iterable
    {
        yield 'missing role' => [false];
        yield 'messenger role' => ['messenger-default'];
        yield 'case-sensitive role' => ['Scheduler'];
        yield 'empty role' => [''];
    }

    #[DataProvider('missingContext')]
    public function testFactoryMissingOwnershipContextDeniesBeforeDatabaseAccess(string $name): void
    {
        $this->setWorkerContext();
        putenv($name);
        $adapter = DoctrineSchedulerWorkerAuthority::fromDsn('postgresql://baander:test-only@127.0.0.1:1/scheduler_test');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scheduler worker requires active deployment authority.');
        $adapter->assertActive();
    }

    /** @return iterable<string, array{string}> */
    public static function missingContext(): iterable
    {
        yield 'namespace' => ['BAANDER_WORKER_NAMESPACE'];
        yield 'boot' => ['BAANDER_WORKER_BOOT_ID'];
        yield 'epoch' => ['BAANDER_WORKER_LEASE_EPOCH'];
    }

    #[DataProvider('malformedContext')]
    public function testFactoryMalformedCompleteContextFailsClosed(string $name, string $value): void
    {
        $this->setWorkerContext();
        putenv($name . '=' . $value);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scheduler worker authority context is invalid.');
        DoctrineSchedulerWorkerAuthority::fromDsn($this->databaseUrl);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedContext(): iterable
    {
        yield 'invalid namespace' => ['BAANDER_WORKER_NAMESPACE', 'baander.app/invalid'];
        yield 'uppercase boot' => ['BAANDER_WORKER_BOOT_ID', str_repeat('A', 32)];
        yield 'zero epoch' => ['BAANDER_WORKER_LEASE_EPOCH', '0'];
        yield 'leading zero epoch' => ['BAANDER_WORKER_LEASE_EPOCH', '01'];
        yield 'overflow epoch' => ['BAANDER_WORKER_LEASE_EPOCH', '999999999999999999999999999999'];
    }

    public function testFactoryExactSchedulerContextChecksRealLeaseAndRechecksRevocation(): void
    {
        $this->setWorkerContext();
        // libpq applies this only to the factory's new connection, keeping the schema disposable.
        putenv('PGOPTIONS=-c search_path=' . $this->schema);
        $adapter = DoctrineSchedulerWorkerAuthority::fromDsn($this->databaseUrl);
        $adapter->assertActive();
        $this->second->executeStatement("UPDATE worker_deployment_leases SET state = 'available'");
        $this->expectException(\RuntimeException::class);
        $adapter->assertActive();
    }

    private function setWorkerContext(): void
    {
        putenv('BAANDER_WORKER_ID=scheduler');
        putenv('BAANDER_WORKER_NAMESPACE=' . $this->lease->namespace);
        putenv('BAANDER_WORKER_BOOT_ID=' . $this->lease->bootId);
        putenv('BAANDER_WORKER_LEASE_EPOCH=' . $this->lease->epoch);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if (isset($this->second, $this->schema)) {
            if ($this->second->isTransactionActive()) {
                $this->second->rollBack();
            }
            $this->second->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->second->close();
        }
        if (isset($this->first)) {
            $this->first->close();
        }
    }
}
