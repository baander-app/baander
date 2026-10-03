<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
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
        foreach (['Version20261002210000', 'Version20261002220000', 'Version20261003020000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->first, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    public function testMissingBindingNeverCallsDocker(): void
    {
        $binding = $this->binding();
        $leases = new DoctrineDeploymentLease($this->first);
        $old = $leases->acquire($binding->namespace, $binding->bootId, 60);
        $recovery = new RegisteredDeploymentRecovery(new DoctrineDeploymentInventory($this->first), new DoctrineDeploymentRetirement($this->first), static function (): string {
            self::fail('An unregistered deployment must not reach Docker.');
        });
        self::assertFalse($recovery->recover($binding->namespace, $binding->bootId));
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
    }

    public function testNumericallyEquivalentImmutableIdsCannotRebindPendingRetirement(): void
    {
        $binding = new DeploymentContainer('baander.app:workers', str_repeat('a', 32), 'baander.app-daemon', '0e' . str_repeat('0', 62));
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->register($binding));
        $leases = new DoctrineDeploymentLease($this->first);
        $old = $leases->acquire($binding->namespace, $binding->bootId, 60);
        self::assertNotNull($old);
        $retirements = new DoctrineDeploymentRetirement($this->first);
        self::assertTrue($retirements->begin($binding), 'The fixture supplies prior trusted isolation verification.');
        $this->first->executeStatement(
            'UPDATE worker_deployment_containers SET container_id = :container WHERE namespace = :namespace AND boot_id = :boot',
            ['container' => '0e' . str_repeat('1', 62), 'namespace' => $binding->namespace, 'boot' => $binding->bootId],
        );
        $recovery = $this->recovery($inventory, static function (): string {
            self::fail('A conflicting immutable binding must not reach Docker.');
        });
        try {
            $recovery->recover($binding->namespace, $binding->bootId);
            self::fail('Numeric string equivalence cannot identify the same immutable container.');
        } catch (\RuntimeException $error) {
            self::assertSame('Retirement intent does not match immutable deployment inventory.', $error->getMessage());
        }
        $intent = (new DoctrineDeploymentRetirement($this->second))->find($binding->namespace, $binding->bootId);
        self::assertNotNull($intent);
        self::assertEquals($binding, $intent->binding);
        self::assertFalse($intent->completed);
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
    }

    public function testRegisteredContainerBeforeLeaseAcquisitionCanBeRetiredAndOldBootCannotAcquire(): void
    {
        $binding = $this->binding();
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->register($binding));
        $calls = [];
        $recovery = $this->recovery($inventory, function (array $arguments) use ($binding, &$calls): string {
            $calls[] = $arguments;
            return $this->dockerResponse($binding, $arguments);
        });
        self::assertTrue($recovery->recover($binding->namespace, $binding->bootId));
        self::assertContains(['container', 'rm', '--force', $binding->containerId], $calls);
        $leases = new DoctrineDeploymentLease($this->second);
        self::assertNull($leases->acquire($binding->namespace, $binding->bootId, 60));
        self::assertNotNull($leases->acquire($binding->namespace, str_repeat('b', 32), 60));
    }

    public function testRetiringOldBindingPreservesForeignOwnerAndCompletedRetryNeverCallsDocker(): void
    {
        $binding = $this->binding();
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->register($binding));
        $leases = new DoctrineDeploymentLease($this->second);
        $foreign = $leases->acquire($binding->namespace, str_repeat('b', 32), 60);
        self::assertNotNull($foreign);
        $recovery = $this->recovery($inventory, fn (array $arguments): string => $this->dockerResponse($binding, $arguments));
        self::assertTrue($recovery->recover($binding->namespace, $binding->bootId));
        self::assertEquals($foreign, $leases->findForContainment($binding->namespace));
        $retry = $this->recovery($inventory, static function (): string {
            self::fail('Completed retirement retries must not call Docker.');
        });
        self::assertTrue($retry->recover($binding->namespace, $binding->bootId));
        self::assertEquals($foreign, $leases->findForContainment($binding->namespace));
        self::assertNull($leases->acquire($binding->namespace, $binding->bootId, 60));
    }

    public function testWrongDaemonNeverInspectsOrRemovesContainer(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $recovery = $this->recovery($inventory, static function (array $arguments) use (&$calls): string {
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
        self::assertNull((new DoctrineDeploymentRetirement($this->second))->find($binding->namespace, $binding->bootId));
    }

    public function testStoredContainerIsOnlyRetirementTargetAndReplacementAdvancesEpoch(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $observer = new DoctrineDeploymentRetirement($this->second);
        $recovery = $this->recovery($inventory, function (array $arguments) use (&$calls, $binding, $observer): string {
            $calls[] = $arguments;
            self::assertSame(0, $this->first->getTransactionNestingLevel(), 'No database transaction spans Docker commands.');
            if ($arguments[0] === 'container' && in_array($arguments[1], ['ls', 'rm'], true)) {
                $intent = $observer->find($binding->namespace, $binding->bootId);
                self::assertNotNull($intent, 'Verified intent must be committed before listing or removal.');
                self::assertEquals($binding, $intent->binding);
                self::assertFalse($intent->completed);
                self::assertSame('active', $this->second->fetchOne('SELECT state FROM worker_deployment_leases'));
            }
            return $this->dockerResponse($binding, $arguments);
        });
        self::assertTrue($recovery->recover($binding->namespace, $binding->bootId));
        self::assertSame(['info', 'inspect', 'info', 'ls', 'info', 'inspect', 'info', 'rm'], array_map(static fn (array $arguments): string => $arguments[0] === 'info' ? 'info' : $arguments[1], $calls));
        self::assertTrue($observer->find($binding->namespace, $binding->bootId)->completed);
        $next = (new DoctrineDeploymentLease($this->second))->acquire($binding->namespace, str_repeat('b', 32), 60);
        self::assertNotNull($next);
        self::assertSame(2, $next->epoch);
        self::assertFalse($leases->acknowledgeContainment($old));
        self::assertFalse($leases->renew($old, 60));
        self::assertNull($leases->acquire($binding->namespace, $binding->bootId, 60));
    }

    public function testDaemonChangeBetweenInspectionAndRemovalLeavesOwnershipReserved(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $checks = 0;
        $recovery = $this->recovery($inventory, function (array $arguments) use (&$calls, &$checks, $binding): string {
            $calls[] = $arguments;
            if ($arguments[0] === 'info') {
                return ++$checks === 1 ? $binding->daemonId : 'baander.app-other-daemon';
            }
            self::assertSame('inspect', $arguments[1], 'A changed daemon must never receive listing or removal.');
            return $this->inspection($binding);
        });
        try {
            $recovery->recover($binding->namespace, $binding->bootId);
            self::fail('Daemon change must reject removal and acknowledgment.');
        } catch (\RuntimeException $error) {
            self::assertSame('Docker daemon does not match the registered deployment.', $error->getMessage());
        }
        self::assertCount(3, $calls);
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
        self::assertNull($leases->acquire($binding->namespace, str_repeat('b', 32), 60));
        $intent = (new DoctrineDeploymentRetirement($this->second))->find($binding->namespace, $binding->bootId);
        self::assertNotNull($intent);
        self::assertFalse($intent->completed);
    }

    public function testLostRemovalReplyLeavesCommittedIntentAndSuccessfulEmptyListingCompletesRetry(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $failure = new \RuntimeException('Docker removal reply was lost');
        $recovery = $this->recovery($inventory, function (array $arguments) use ($binding, $failure): string {
            self::assertFalse($this->first->isTransactionActive());
            if ($arguments[0] === 'container' && $arguments[1] === 'rm') {
                throw $failure;
            }
            return $this->dockerResponse($binding, $arguments);
        });
        try {
            $recovery->recover($binding->namespace, $binding->bootId);
            self::fail('Lost removal reply must preserve pending retirement.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        $observer = new DoctrineDeploymentRetirement($this->second);
        self::assertFalse($observer->find($binding->namespace, $binding->bootId)->completed);
        self::assertEquals($old, $leases->findForContainment($binding->namespace));
        self::assertNull($leases->acquire($binding->namespace, $binding->bootId, 60));
        $calls = [];
        $retry = $this->recovery($inventory, function (array $arguments) use ($binding, &$calls): string {
            $calls[] = $arguments;
            self::assertFalse($this->first->isTransactionActive());
            if ($arguments[0] === 'info') {
                return $binding->daemonId;
            }
            self::assertSame($this->listing($binding), $arguments, 'Pending removal retry needs only exact-ID absence.');
            return '';
        });
        self::assertTrue($retry->recover($binding->namespace, $binding->bootId));
        self::assertSame([['info', '--format', '{{.ID}}'], $this->listing($binding)], $calls);
        self::assertTrue($observer->find($binding->namespace, $binding->bootId)->completed);
        self::assertNull($leases->findForContainment($binding->namespace));
        $next = $leases->acquire($binding->namespace, str_repeat('b', 32), 60);
        self::assertNotNull($next);
        self::assertSame(2, $next->epoch);
    }

    public function testAbsenceWithoutVerifiedIntentNeverCountsAsRetirement(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $recovery = $this->recovery($inventory, static function (array $arguments) use ($binding, &$calls): string {
            $calls[] = $arguments;
            if ($arguments[0] === 'info') {
                return $binding->daemonId;
            }
            self::assertSame('inspect', $arguments[1], 'No intent means absence must not progress to listing.');
            return '';
        });
        $this->assertRejectedWithoutIntent($recovery, $binding, $old, $calls);
    }

    public function testNonisolatedContainerNeverCreatesRetirementIntentOrAttemptsRemoval(): void
    {
        [$binding, $inventory, $leases, $old] = $this->registeredOwner();
        $calls = [];
        $recovery = $this->recovery($inventory, function (array $arguments) use ($binding, &$calls): string {
            $calls[] = $arguments;
            if ($arguments[0] === 'info') {
                return $binding->daemonId;
            }
            self::assertSame('inspect', $arguments[1]);
            $inspection = json_decode($this->inspection($binding), true, 16, JSON_THROW_ON_ERROR);
            $inspection['pidMode'] = 'host';
            return json_encode($inspection, JSON_THROW_ON_ERROR);
        });
        $this->assertRejectedWithoutIntent($recovery, $binding, $old, $calls);
    }

    /** @param list<list<string>> $calls */
    private function assertRejectedWithoutIntent(RegisteredDeploymentRecovery $recovery, DeploymentContainer $binding, DeploymentLease $old, array &$calls): void
    {
        try {
            $recovery->recover($binding->namespace, $binding->bootId);
            self::fail('Unverified isolation cannot establish durable retirement.');
        } catch (\RuntimeException) {
            self::assertCount(2, $calls);
        }
        self::assertEquals($old, (new DoctrineDeploymentLease($this->second))->findForContainment($binding->namespace));
        self::assertNull((new DoctrineDeploymentRetirement($this->second))->find($binding->namespace, $binding->bootId));
    }

    private function recovery(DoctrineDeploymentInventory $inventory, \Closure $execute): RegisteredDeploymentRecovery
    {
        return new RegisteredDeploymentRecovery($inventory, new DoctrineDeploymentRetirement($this->first), $execute);
    }

    /** @param list<string> $arguments */
    private function dockerResponse(DeploymentContainer $binding, array $arguments): string
    {
        if ($arguments[0] === 'info') {
            return $binding->daemonId;
        }
        if ($arguments[1] === 'ls') {
            self::assertSame($this->listing($binding), $arguments);
            return $binding->containerId;
        }
        self::assertSame($binding->containerId, $arguments[array_key_last($arguments)]);
        if ($arguments[1] === 'inspect') {
            return $this->inspection($binding);
        }
        self::assertSame(['container', 'rm', '--force', $binding->containerId], $arguments);
        return $binding->containerId;
    }

    /** @return list<string> */
    private function listing(DeploymentContainer $binding): array
    {
        return ['container', 'ls', '--all', '--no-trunc', '--filter', 'id=' . $binding->containerId, '--format', '{{.ID}}'];
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
