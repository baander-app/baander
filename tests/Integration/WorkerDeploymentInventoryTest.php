<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Actual PostgreSQL inventory registration before any worker lease/container start. */
final class WorkerDeploymentInventoryTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    /** @var array<string, mixed> */
    private array $params;
    /** @var list<Connection> */
    private array $extras = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($this->params);
        $this->second = DriverManager::getConnection($this->params);
        $this->schema = 'deployment_inventory_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002220000.php';
        $migration = new \DoctrineMigrations\Version20261002220000($this->first, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    public function testCommittedRegistrationAndExactRetryPreserveOriginalBindingAndTimestamp(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        $binding = $this->binding();
        self::assertNull($inventory->find($binding->namespace, $binding->bootId));
        self::assertTrue($inventory->register($binding));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        $timestamp = $this->second->fetchOne('SELECT created_at FROM worker_deployment_containers');
        self::assertEquals($binding, (new DoctrineDeploymentInventory($this->second))->find($binding->namespace, $binding->bootId));
        self::assertTrue($inventory->register($binding));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_containers'));
        self::assertSame($timestamp, $this->second->fetchOne('SELECT created_at FROM worker_deployment_containers'));
        self::assertSame('timestamp with time zone', $this->second->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'worker_deployment_containers' AND column_name = 'created_at'", ['schema' => $this->schema]));
    }

    public function testBootCannotRebindToDifferentDaemonOrContainer(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        $original = $this->binding();
        self::assertTrue($inventory->register($original));
        self::assertFalse($inventory->register($this->binding(daemon: 'baander.app:other-daemon')));
        self::assertFalse($inventory->register($this->binding(container: str_repeat('d', 64))));
        self::assertEquals($original, (new DoctrineDeploymentInventory($this->second))->find($original->namespace, $original->bootId));
    }

    public function testOneDaemonContainerCannotBeRegisteredToAnotherBootOrNamespace(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->register($this->binding()));
        self::assertFalse($inventory->register($this->binding(boot: str_repeat('b', 32))));
        self::assertFalse($inventory->register($this->binding(namespace: 'baander.app:other-workers')));
        self::assertNull($inventory->find('baander.app:workers', str_repeat('b', 32)));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_containers'));
        self::assertTrue($inventory->register($this->binding(boot: str_repeat('b', 32), daemon: 'baander.app:other-daemon')), 'Full container IDs are scoped to their recorded Docker daemon.');
    }

    #[DataProvider('transactionOutcomes')]
    public function testIndependentUncommittedContenderTimesOutThenReconcilesCommittedOutcome(bool $commit): void
    {
        $winner = $this->binding(container: str_repeat('d', 64));
        $this->second->beginTransaction();
        $this->second->executeStatement('INSERT INTO worker_deployment_containers (namespace, boot_id, daemon_id, container_id) VALUES (:namespace, :boot, :daemon, :container)', ['namespace' => $winner->namespace, 'boot' => $winner->bootId, 'daemon' => $winner->daemonId, 'container' => $winner->containerId]);
        $inventory = new DoctrineDeploymentInventory($this->first, 500, 50);
        $started = microtime(true);
        try {
            $inventory->register($this->binding());
            self::fail('Actual competing unique-key registration must wait for the other transaction and time out.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
            self::assertLessThan(2.0, microtime(true) - $started);
        }
        self::assertFalse($this->first->isConnected());
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        if ($commit) {
            $this->second->commit();
        } else {
            $this->second->rollBack();
        }
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        self::assertSame(!$commit, $inventory->register($this->binding()));
        self::assertEquals($commit ? $winner : $this->binding(), $inventory->find($winner->namespace, $winner->bootId));
    }

    /** @return iterable<string, array{bool}> */
    public static function transactionOutcomes(): iterable
    {
        yield 'contender commits' => [true];
        yield 'contender rolls back' => [false];
    }

    public function testUncertainCommittedRegistrationReconcilesOnlyExactBinding(): void
    {
        $params = $this->params;
        $params['wrapperClass'] = InventoryCommitThenThrowConnection::class;
        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(InventoryCommitThenThrowConnection::class, $connection);
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->throwAfterCommit = true;
        try {
            (new DoctrineDeploymentInventory($connection))->register($this->binding());
            self::fail('Injected lost commit acknowledgment must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Inventory commit acknowledgment is uncertain.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $observer = new DoctrineDeploymentInventory($this->second);
        self::assertEquals($this->binding(), $observer->find('baander.app:workers', str_repeat('a', 32)));
        self::assertTrue($observer->register($this->binding()));
        self::assertFalse($observer->register($this->binding(container: str_repeat('d', 64))));
    }

    public function testOuterTransactionIsRejectedWithoutCommittingOrRegistering(): void
    {
        $this->first->beginTransaction();
        try {
            (new DoctrineDeploymentInventory($this->first))->register($this->binding());
            self::fail('Registration must not hide inside a caller transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('idle autocommit', $error->getMessage());
        }
        self::assertSame(1, $this->first->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_containers'));
        $this->first->rollBack();
    }

    public function testMigrationChecksRejectInvalidRawBindingIndependentlyOfValueObject(): void
    {
        try {
            $this->first->executeStatement('INSERT INTO worker_deployment_containers (namespace, boot_id, daemon_id, container_id) VALUES (:namespace, :boot, :daemon, :container)', ['namespace' => 'baander.app:workers', 'boot' => 'invalid', 'daemon' => 'baander.app:daemon', 'container' => str_repeat('c', 64)]);
            self::fail('The actual migration must enforce boot identity format.');
        } catch (DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
        }
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_containers'));
    }

    private function binding(string $namespace = 'baander.app:workers', string $boot = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', string $daemon = 'baander.app:daemon', string $container = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'): DeploymentContainer
    {
        return new DeploymentContainer($namespace, $boot, $daemon, $container);
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null, ...$this->extras] as $connection) {
            if ($connection !== null) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
        if (isset($this->schema)) {
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->first ?? null, $this->second ?? null, ...$this->extras] as $connection) {
            $connection?->close();
        }
    }
}

/** Real PostgreSQL commits first; only acknowledgment loss is injected. */
final class InventoryCommitThenThrowConnection extends Connection
{
    public bool $throwAfterCommit = false;

    public function commit(): void
    {
        parent::commit();
        if ($this->throwAfterCommit) {
            $this->throwAfterCommit = false;
            throw new \RuntimeException('Inventory commit acknowledgment is uncertain.');
        }
    }
}
