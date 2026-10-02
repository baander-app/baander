<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real committed lease state and independent PostgreSQL contenders; no process-containment claim. */
final class WorkerDeploymentLeaseTest extends TestCase
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
        $this->schema = 'worker_lease_test_' . bin2hex(random_bytes(8));
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
    }

    public function testOnlyOneOwnerCanClaimAndRenewCommittedNamespace(): void
    {
        $first = new DoctrineDeploymentLease($this->first);
        $second = new DoctrineDeploymentLease($this->second);
        $lease = $first->acquire('baander.app:workers', str_repeat('a', 32), 60);
        self::assertNotNull($lease);
        self::assertSame(1, $lease->epoch);
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertSame('active', $this->second->fetchOne('SELECT state FROM worker_deployment_leases'));
        self::assertNull($second->acquire($lease->namespace, str_repeat('b', 32), 60));
        self::assertNull($second->acquire($lease->namespace, $lease->bootId, 60), 'Duplicate same-boot calls do not grant admission.');
        self::assertTrue($first->renew($lease, 120));
        self::assertFalse($second->renew(new DeploymentLease($lease->namespace, str_repeat('b', 32), 1), 60));
        self::assertFalse($second->acknowledgeContainment(new DeploymentLease($lease->namespace, $lease->bootId, 2)));
        self::assertSame('timestamp with time zone', $this->second->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'worker_deployment_leases' AND column_name = 'expires_at'", ['schema' => $this->schema]));
        self::assertSame('0', $this->first->fetchOne('SHOW lock_timeout'), 'Transaction-local timeouts do not leak into later work.');
    }

    public function testExpiryAloneNeverAllowsTakeoverAndContainmentAdvancesEpoch(): void
    {
        $first = new DoctrineDeploymentLease($this->first);
        $second = new DoctrineDeploymentLease($this->second);
        $old = $first->acquire('deployment', str_repeat('a', 32), 60);
        self::assertNotNull($old);
        // Deterministically expire the fixture using the database clock, without sleeping.
        $this->second->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - interval '1 second'");
        self::assertFalse($first->renew($old, 60));
        self::assertNull($second->acquire('deployment', str_repeat('b', 32), 60));
        self::assertEquals($old, $second->findForContainment('deployment'));
        self::assertTrue($second->acknowledgeContainment($old), 'Fixture controller assumes predecessor cleanup was verified externally.');
        self::assertFalse($second->acknowledgeContainment($old));
        self::assertNull($second->findForContainment('deployment'));
        $new = $second->acquire('deployment', str_repeat('b', 32), 60);
        self::assertNotNull($new);
        self::assertSame(2, $new->epoch);
        self::assertFalse($first->renew($old, 60));
        self::assertFalse($first->acknowledgeContainment($old));
        self::assertTrue($second->renew($new, 60));
    }

    public function testRowContentionFailsWithinLockTimeoutAndLeavesLeaseUnchanged(): void
    {
        $first = new DoctrineDeploymentLease($this->first, 500, 50);
        $second = new DoctrineDeploymentLease($this->second);
        $lease = $second->acquire('deployment', str_repeat('a', 32), 60);
        self::assertNotNull($lease);
        $this->second->beginTransaction();
        $this->second->executeQuery('SELECT namespace FROM worker_deployment_leases FOR UPDATE')->free();
        $started = microtime(true);
        try {
            $first->acquire('deployment', str_repeat('b', 32), 60);
            self::fail('Independent contender must hit the real PostgreSQL row lock timeout.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
            self::assertLessThan(2.0, microtime(true) - $started);
        } finally {
            $this->second->rollBack();
        }
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertFalse($this->first->isConnected(), 'A failed operation discards the dedicated connection.');
        // Reapply this fixture's session configuration after reconnecting.
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        self::assertEquals($lease, $first->findForContainment('deployment'));
        self::assertTrue($first->renew($lease, 60), 'A timed-out operation rolls back and leaves the dedicated connection usable.');
    }

    public function testRenewalCannotResurrectLeaseThatExpiresWhileWaitingForUnchangedRow(): void
    {
        $repository = new DoctrineDeploymentLease($this->first, 2000, 1500);
        $lease = $repository->acquire('deployment', str_repeat('a', 32), 60);
        self::assertNotNull($lease);
        $this->first->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() + interval '1 second'");
        $marker = sys_get_temp_dir() . '/baander-lease-lock-' . bin2hex(random_bytes(12));
        $output = tmpfile();
        $code = <<<'PHP'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2]);
$connection->beginTransaction();
$connection->executeQuery('SELECT namespace FROM worker_deployment_leases FOR UPDATE')->free();
file_put_contents($argv[3], 'locked');
usleep(1200000);
$connection->commit();
PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $marker], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 3;
            while (!is_file($marker)) {
                if (microtime(true) >= $deadline) {
                    self::fail('Fresh PostgreSQL locker did not become ready.');
                }
                usleep(1000);
            }
            self::assertTrue((bool) $this->second->fetchOne('SELECT expires_at > clock_timestamp() FROM worker_deployment_leases'), 'Renewal begins before expiry, while another connection holds the unchanged row.');
            self::assertFalse($repository->renew($lease, 60), 'Expiry must be evaluated after the contested row lock is acquired.');
            self::assertSame(0, proc_close($process));
            self::assertFalse((bool) $this->second->fetchOne('SELECT expires_at > clock_timestamp() FROM worker_deployment_leases'));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($output);
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function testActualDriverBeginFailureDiscardsInconsistentDbalTransactionState(): void
    {
        // Trigger an actual PDO begin failure: DBAL observes nesting zero, then
        // increments it before the driver detects this existing transaction.
        $native = $this->first->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        self::assertTrue($native->beginTransaction());
        try {
            (new DoctrineDeploymentLease($this->first))->acquire('deployment', str_repeat('a', 32), 60);
            self::fail('The real driver must reject a second begin.');
        } catch (DriverException $error) {
            self::assertStringContainsString('active transaction', $error->getMessage());
        } finally {
            if ($native->inTransaction()) {
                $native->rollBack();
            }
        }
        self::assertFalse($this->first->isConnected());
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_leases'));
    }

    public function testCallerTransactionIsRejectedWithoutCommittingIt(): void
    {
        $this->first->beginTransaction();
        try {
            (new DoctrineDeploymentLease($this->first))->acquire('deployment', str_repeat('a', 32), 60);
            self::fail('An outer transaction must not hide committed lease ownership.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('idle autocommit', $error->getMessage());
        }
        self::assertSame(1, $this->first->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_leases'));
        $this->first->rollBack();
    }

    public function testUncertainCommittedAcquireNeedsControllerReconciliation(): void
    {
        $params = $this->params;
        $params['wrapperClass'] = CommitThenThrowLeaseConnection::class;
        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(CommitThenThrowLeaseConnection::class, $connection);
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->throwAfterCommit = true;
        $acknowledged = null;
        try {
            $acknowledged = (new DoctrineDeploymentLease($connection))->acquire('deployment', str_repeat('a', 32), 60);
            self::fail('Injected post-commit uncertainty must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected uncertain commit acknowledgment.', $error->getMessage());
        }
        self::assertNull($acknowledged);
        self::assertFalse($connection->isConnected(), 'Uncertain commit acknowledgment discards the connection even at nesting zero.');
        $controller = new DoctrineDeploymentLease($this->second);
        self::assertNull($controller->acquire('deployment', str_repeat('a', 32), 60));
        $reserved = $controller->findForContainment('deployment');
        self::assertNotNull($reserved);
        self::assertSame(1, $reserved->epoch);
        self::assertTrue($controller->acknowledgeContainment($reserved), 'No child was launched by this fixture; containment is verified externally.');
        $replacement = $controller->acquire('deployment', str_repeat('b', 32), 60);
        self::assertNotNull($replacement);
        self::assertSame(2, $replacement->epoch);
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

/** Only the acknowledgment is fault-injected: the actual PostgreSQL transaction commits first. */
final class CommitThenThrowLeaseConnection extends Connection
{
    public bool $throwAfterCommit = false;

    public function commit(): void
    {
        parent::commit();
        if ($this->throwAfterCommit) {
            $this->throwAfterCommit = false;
            throw new \RuntimeException('Injected uncertain commit acknowledgment.');
        }
    }
}
