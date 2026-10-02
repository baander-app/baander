<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Committed PostgreSQL registration and lease state; Docker frames are controlled fixtures. */
final class RegisteredDeploymentRecoveryTest extends TestCase
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
        $this->schema = 'registered_recovery_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        foreach (['Version20261002210000', 'Version20261002220000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->first, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    #[DataProvider('missingPrerequisites')]
    public function testMissingBindingOrOwnerNeverCallsDocker(bool $register, bool $acquire): void
    {
        $binding = $this->binding();
        $inventory = new DoctrineDeploymentInventory($this->first);
        $leases = new DoctrineDeploymentLease($this->first);
        if ($register) {
            self::assertTrue($inventory->register($binding));
        }
        $old = $acquire ? $leases->acquire($binding->namespace, $binding->bootId, 60) : null;
        $recovery = new RegisteredDeploymentRecovery($inventory, $leases, static function (): string {
            self::fail('An unregistered or unowned deployment must not reach Docker.');
        });
        self::assertFalse($recovery->recover($binding->namespace, $binding->bootId));
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
    }

    public static function missingPrerequisites(): iterable
    {
        yield 'lease without binding' => [false, true];
        yield 'binding without lease' => [true, false];
    }

    public function testWrongOwnerNeverCallsDockerOrReleasesRegisteredLease(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        self::assertTrue($inventory->register(new DeploymentContainer($binding->namespace, str_repeat('b', 32), $binding->daemonId, str_repeat('d', 64))), 'A committed binding does not establish current lease ownership.');
        $recovery = new RegisteredDeploymentRecovery($inventory, $leases, static function (): string {
            self::fail('Wrong ownership must not reach Docker.');
        });
        self::assertFalse($recovery->recover($binding->namespace, str_repeat('b', 32)));
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
    }

    public function testWrongDaemonNeverInspectsOrRemovesContainer(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $recovery = new RegisteredDeploymentRecovery($inventory, $leases, static function (array $arguments) use (&$calls): string {
            $calls[] = $arguments;
            return "baander.app-other-daemon\n";
        });
        try {
            $recovery->recover($binding->namespace, $binding->bootId);
            self::fail('Wrong daemon must reject recovery.');
        } catch (\RuntimeException $error) {
            self::assertSame('Docker daemon does not match the registered deployment.', $error->getMessage());
        }
        self::assertSame([['info', '--format', '{{.ID}}']], $calls);
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
    }

    public function testStoredContainerIsOnlyRetirementTargetAndReplacementAdvancesEpoch(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $recovery = new RegisteredDeploymentRecovery($inventory, $leases, function (array $arguments) use (&$calls, $binding): string {
            $calls[] = $arguments;
            if ($arguments[0] === 'info') {
                return $binding->daemonId . "\n";
            }
            self::assertSame($binding->containerId, $arguments[array_key_last($arguments)]);
            self::assertSame(0, $this->first->getTransactionNestingLevel(), 'Docker calls occur outside the database read transaction.');
            if ($arguments[1] === 'inspect') {
                return $this->inspection($binding);
            }
            self::assertSame(['container', 'rm', '--force', $binding->containerId], $arguments);
            self::assertSame('active', $this->second->fetchOne('SELECT state FROM worker_deployment_leases'), 'Removal precedes database acknowledgment.');
            return $binding->containerId . "\n";
        });
        self::assertTrue($recovery->recover($binding->namespace, $binding->bootId));
        self::assertSame(4, count($calls));
        self::assertSame(['info', '--format', '{{.ID}}'], $calls[0]);
        self::assertSame(['info', '--format', '{{.ID}}'], $calls[2]);
        $next = (new DoctrineDeploymentLease($this->second))->acquire($binding->namespace, str_repeat('b', 32), 60);
        self::assertNotNull($next);
        self::assertSame(2, $next->epoch);
        self::assertFalse($leases->acknowledgeContainment($old));
        self::assertFalse($leases->renew($old, 60));
    }

    public function testDaemonChangeBetweenInspectionAndRemovalLeavesOwnershipReserved(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $checks = 0;
        $recovery = new RegisteredDeploymentRecovery($inventory, $leases, function (array $arguments) use (&$calls, &$checks, $binding): string {
            $calls[] = $arguments;
            if ($arguments[0] === 'info') {
                return ++$checks === 1 ? $binding->daemonId : 'baander.app-other-daemon';
            }
            self::assertSame('inspect', $arguments[1], 'A changed daemon must never receive removal.');
            return $this->inspection($binding);
        });
        try {
            $recovery->recover($binding->namespace, $binding->bootId);
            self::fail('Daemon change must reject removal and acknowledgment.');
        } catch (\RuntimeException $error) {
            self::assertSame('Docker daemon does not match the registered deployment.', $error->getMessage());
        }
        self::assertSame(3, count($calls));
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
        self::assertNull($leases->acquire($binding->namespace, str_repeat('b', 32), 60));
    }

    public function testConcurrentEpochAdvancementCannotBeReleasedByRegisteredRecovery(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $other = new DoctrineDeploymentLease($this->second);
        $next = null;
        $recovery = new RegisteredDeploymentRecovery($inventory, $leases, function (array $arguments) use ($binding, $old, $other, &$next): string {
            if ($arguments[0] === 'info') {
                return $binding->daemonId;
            }
            if ($arguments[1] === 'inspect') {
                return $this->inspection($binding);
            }
            self::assertTrue($other->acknowledgeContainment($old));
            $next = $other->acquire($binding->namespace, str_repeat('b', 32), 60);
            return $binding->containerId;
        });
        self::assertFalse($recovery->recover($binding->namespace, $binding->bootId));
        self::assertNotNull($next);
        self::assertSame(2, $next->epoch);
        self::assertEquals($next, $leases->findForContainment($binding->namespace));
        self::assertFalse($leases->acknowledgeContainment($old));
    }

    private function binding(): DeploymentContainer
    {
        return new DeploymentContainer('baander.app:workers', str_repeat('a', 32), 'baander.app-daemon', str_repeat('c', 64));
    }

    /** @return array{DeploymentContainer, DoctrineDeploymentInventory, DoctrineDeploymentLease, DeploymentLease} */
    private function registeredOwner(): array
    {
        $binding = $this->binding();
        $inventory = new DoctrineDeploymentInventory($this->first);
        $leases = new DoctrineDeploymentLease($this->first);
        self::assertTrue($inventory->register($binding));
        $old = $leases->acquire($binding->namespace, $binding->bootId, 60);
        self::assertNotNull($old);
        return [$binding, $inventory, $leases, $old];
    }

    private function inspection(DeploymentContainer $binding): string
    {
        return json_encode(['id' => $binding->containerId, 'namespace' => $binding->namespace, 'bootId' => $binding->bootId, 'role' => 'deployment',
            'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private', 'mounts' => [], 'binds' => null, 'tmpfs' => null,
            'devices' => [], 'deviceRequests' => [], 'capAdd' => null, 'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges']], JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $connection) {
            if ($connection !== null) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
        if (isset($this->schema)) {
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->first ?? null, $this->second ?? null] as $connection) {
            $connection?->close();
        }
    }
}
