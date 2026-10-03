<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainmentController;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real PostgreSQL coordination; callbacks assume external retirement proof, not Docker verification. */
final class WorkerDeploymentContainmentTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);
        $this->schema = 'deployment_containment_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        foreach (['Version20261002210000', 'Version20261003020000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->first, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    public function testSuccessfulRetirementAcknowledgesOnlyFetchedOwnerAndAllowsNextEpoch(): void
    {
        $leases = new DoctrineDeploymentLease($this->first);
        $old = $leases->acquire('baander.app:workers', str_repeat('a', 32), 60);
        self::assertNotNull($old);
        $retired = [];
        $controller = new DeploymentContainmentController($leases, function (string $id, DeploymentLease $lease) use (&$retired): void {
            self::assertSame(0, $this->first->getTransactionNestingLevel(), 'Docker retirement occurs outside the lease read transaction.');
            self::assertSame('active', $this->second->fetchOne('SELECT state FROM worker_deployment_leases'), 'Acknowledgment follows successful retirement.');
            $retired[] = [$id, $lease];
        });
        self::assertTrue($controller->recover($old->namespace, $old->bootId, str_repeat('c', 64)));
        self::assertEquals([[str_repeat('c', 64), $old]], $retired);
        $replacement = (new DoctrineDeploymentLease($this->second))->acquire($old->namespace, str_repeat('b', 32), 60);
        self::assertNotNull($replacement);
        self::assertSame(2, $replacement->epoch);
        self::assertFalse($leases->renew($old, 60));
    }

    public function testWrongExpectedOwnerAndMissingLeaseNeverRetireOrAcknowledge(): void
    {
        $leases = new DoctrineDeploymentLease($this->first);
        $old = $leases->acquire('baander.app:workers', str_repeat('a', 32), 60);
        self::assertNotNull($old);
        $controller = new DeploymentContainmentController($leases, static function (): void {
            self::fail('Wrong or missing ownership must not reach Docker retirement.');
        });
        self::assertFalse($controller->recover($old->namespace, str_repeat('b', 32), str_repeat('c', 64)));
        self::assertFalse($controller->recover('missing', $old->bootId, str_repeat('c', 64)));
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($old->namespace));
        self::assertNull($leases->acquire($old->namespace, str_repeat('b', 32), 60));
    }

    public function testFailedOrUncertainRetirementLeavesLeaseActiveAndPreservesError(): void
    {
        $leases = new DoctrineDeploymentLease($this->first);
        $old = $leases->acquire('baander.app:workers', str_repeat('a', 32), 60);
        self::assertNotNull($old);
        $failure = new \RuntimeException('Fixture Docker retirement outcome is uncertain.');
        $controller = new DeploymentContainmentController($leases, static function () use ($failure): void {
            throw $failure;
        });
        try {
            $controller->recover($old->namespace, $old->bootId, str_repeat('c', 64));
            self::fail('Retirement failure must propagate before acknowledgment.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($old->namespace));
        self::assertNull($leases->acquire($old->namespace, str_repeat('b', 32), 60));
    }

    #[DataProvider('replacementOwners')]
    public function testConcurrentAdvancementDuringRetirementCannotReleaseNewerEpoch(string $replacementBoot): void
    {
        $leases = new DoctrineDeploymentLease($this->first);
        $otherController = new DoctrineDeploymentLease($this->second);
        $old = $leases->acquire('baander.app:workers', str_repeat('a', 32), 60);
        self::assertNotNull($old);
        $new = null;
        $controller = new DeploymentContainmentController($leases, static function (string $id, DeploymentLease $retiring) use ($otherController, $replacementBoot, &$new): void {
            self::assertSame(str_repeat('c', 64), $id);
            // Model another trusted controller completing retirement and a new
            // committed acquisition while this controller still has its old token.
            self::assertTrue($otherController->acknowledgeContainment($retiring));
            $new = $otherController->acquire($retiring->namespace, $replacementBoot, 60);
            self::assertNotNull($new);
        });
        self::assertFalse($controller->recover($old->namespace, $old->bootId, str_repeat('c', 64)));
        self::assertNotNull($new);
        self::assertSame(2, $new->epoch);
        self::assertEquals($new, $leases->findForContainment($old->namespace));
        self::assertTrue($otherController->renew($new, 60));
        self::assertFalse($leases->acknowledgeContainment($old));
    }

    /** @return iterable<string, array{string}> */
    public static function replacementOwners(): iterable
    {
        yield 'different boot' => [str_repeat('b', 32)];
        yield 'same boot newer epoch' => [str_repeat('a', 32)];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargetIsRejectedBeforeDatabaseOrRetirement(string $namespace, string $boot, string $id): void
    {
        $this->first->close();
        $controller = new DeploymentContainmentController(new DoctrineDeploymentLease($this->first), static function (): void {
            self::fail('Invalid recovery target must not invoke retirement.');
        });
        try {
            $controller->recover($namespace, $boot, $id);
            self::fail('Invalid recovery target must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertFalse($this->first->isConnected(), 'Input rejection must precede any database connection.');
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidTargets(): iterable
    {
        yield 'bad namespace' => ['../workers', str_repeat('a', 32), str_repeat('c', 64)];
        yield 'bad boot' => ['baander.app:workers', str_repeat('A', 32), str_repeat('c', 64)];
        yield 'container name' => ['baander.app:workers', str_repeat('a', 32), 'baander-workers'];
        yield 'short container ID' => ['baander.app:workers', str_repeat('a', 32), str_repeat('c', 12)];
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->first ?? null, $this->second ?? null] as $connection) {
            $connection?->close();
        }
    }
}
