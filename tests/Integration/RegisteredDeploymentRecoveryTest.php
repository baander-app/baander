<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\DTO\DeploymentUnit;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

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
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        \App\Tests\Support\FinalSchemaBootstrap::bootstrap($this->first);
    }

    public function testMissingBindingNeverCallsDocker(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertFalse($this->recovery($inventory, static function (): string {
            self::fail('An unreserved deployment must not reach Docker.');
        })->recover(self::NAMESPACE, self::BOOT));
    }

    public function testChangedRecipeCannotRetireRegisteredContainer(): void
    {
        $this->registeredOwner();
        $recipes = $this->recipes();
        $recipes['execution'] = $this->recipe(DeploymentUnit::Execution, 'replacement-ipc');
        $recovery = new RegisteredDeploymentRecovery(new DoctrineDeploymentInventory($this->first), new DoctrineDeploymentRetirement($this->first), static function (): string {
            self::fail('Changed recipes cannot reach Docker.');
        }, $recipes);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Recovery recipes differ');
        $recovery->recover(self::NAMESPACE, self::BOOT);
    }

    public function testNumericallyEquivalentImmutableIdsCannotRebindRegisteredContainer(): void
    {
        $this->reserve();
        $inventory = new DoctrineDeploymentInventory($this->first);
        $binding = new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, '0e' . str_repeat('0', 62), DeploymentUnit::Execution);
        $wrong = new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, '0e' . str_repeat('1', 62), DeploymentUnit::Execution);
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
        self::assertTrue($inventory->register($binding));

        self::assertFalse($inventory->register($wrong), 'Numeric string equivalence cannot acknowledge another immutable container.');
        $stored = (new DoctrineDeploymentInventory($this->second))->findUnit(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution);
        self::assertNotNull($stored);
        self::assertSame($binding->containerId, $stored->containerId);
    }

    public function testNumericallyEquivalentImmutableIdsCannotRebindPendingRetirement(): void
    {
        $this->reserve();
        $inventory = new DoctrineDeploymentInventory($this->first);
        $binding = new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, '0e' . str_repeat('0', 62), DeploymentUnit::Execution);
        $wrong = new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, '0e' . str_repeat('1', 62), DeploymentUnit::Execution);
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
        self::assertTrue($inventory->register($binding));
        $retirement = new DoctrineDeploymentRetirement($this->first);
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        self::assertTrue($retirement->beginUnitRetirement($binding));

        self::assertFalse($retirement->beginUnitRetirement($wrong), 'Numeric string equivalence cannot acknowledge another immutable retirement target.');
        $intent = (new DoctrineDeploymentRetirement($this->second))->findUnit(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution);
        self::assertNotNull($intent);
        self::assertSame($binding->containerId, $intent->binding->containerId);
        self::assertFalse($intent->completed);
        self::assertFalse((new DoctrineDeploymentInventory($this->second))->findBoot(self::NAMESPACE, self::BOOT)->retired);
    }

    public function testRegisteredContainersBeforeLeaseAcquisitionCanBeRetiredAndOldBootCannotAcquire(): void
    {
        $this->reserveAndBind();
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), $this->executor())->recover(self::NAMESPACE, self::BOOT));
        self::assertNull((new DoctrineDeploymentLease($this->second))->acquire(self::NAMESPACE, self::BOOT, 60));
    }

    public function testRetiringOldBootPreservesForeignOwnerAndCompletedRetryNeverCallsDocker(): void
    {
        $this->reserveAndBind();
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), $this->executor())->recover(self::NAMESPACE, self::BOOT));
        \App\Tests\Support\DeploymentJournalFixture::ready($this->second, self::NAMESPACE, str_repeat('b', 32));
        $leases = new DoctrineDeploymentLease($this->second);
        $foreign = $leases->acquire(self::NAMESPACE, str_repeat('b', 32), 60);
        self::assertNotNull($foreign);
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), $this->executor())->recover(self::NAMESPACE, self::BOOT));
        self::assertEquals($foreign, $leases->findForContainment(self::NAMESPACE));
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), static function (): string {
            self::fail('Completed recovery cannot call Docker.');
        })->recover(self::NAMESPACE, self::BOOT));
        self::assertEquals($foreign, $leases->findForContainment(self::NAMESPACE));
    }

    public function testWrongDaemonNeverInspectsOrRemovesContainer(): void
    {
        $old = $this->registeredOwner();
        $calls = [];
        try {
            $this->recovery(new DoctrineDeploymentInventory($this->first), static function (array $arguments) use (&$calls): string {
                $calls[] = $arguments;
                return 'other.baander.app';
            })->recover(self::NAMESPACE, self::BOOT);
            self::fail('Wrong daemon must reject recovery.');
        } catch (\RuntimeException) {
            self::assertSame([['info', '--format', '{{.ID}}']], $calls);
        }
        $this->assertReserved($old);
        self::assertNull((new DoctrineDeploymentRetirement($this->second))->findUnit(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
    }

    public function testStoredContainersAreOnlyRetirementTargetsAndReplacementAdvancesEpoch(): void
    {
        $old = $this->registeredOwner();
        $removed = [];
        $base = $this->executor();
        $execute = function (array $arguments) use ($base, &$removed): string {
            self::assertSame(0, $this->first->getTransactionNestingLevel());
            if ($arguments[0] === 'container' && $arguments[1] === 'rm') {
                $unit = $arguments[3] === $this->id(DeploymentUnit::Execution) ? DeploymentUnit::Execution : DeploymentUnit::Control;
                $intent = (new DoctrineDeploymentRetirement($this->second))->findUnit(self::NAMESPACE, self::BOOT, $unit);
                self::assertNotNull($intent);
                self::assertSame($arguments[3], $intent->binding->containerId);
                self::assertFalse($intent->completed);
                $removed[] = $arguments[3];
                self::assertSame('active', $this->second->fetchOne('SELECT state FROM worker_deployment_leases'));
            }
            return $base($arguments);
        };
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), $execute)->recover(self::NAMESPACE, self::BOOT));
        self::assertSame([$this->id(DeploymentUnit::Execution), $this->id(DeploymentUnit::Control)], $removed);
        $leases = new DoctrineDeploymentLease($this->second);
        self::assertNull($leases->findForContainment(self::NAMESPACE));
        \App\Tests\Support\DeploymentJournalFixture::ready($this->second, self::NAMESPACE, str_repeat('b', 32));
        $next = $leases->acquire(self::NAMESPACE, str_repeat('b', 32), 60);
        self::assertNotNull($next);
        self::assertSame(2, $next->epoch);
        self::assertFalse($leases->renew($old, 60));
        self::assertNull($leases->acquire(self::NAMESPACE, self::BOOT, 60));
    }

    public function testDaemonChangeBetweenInspectionAndRemovalLeavesOwnershipReserved(): void
    {
        $old = $this->registeredOwner();
        $checks = 0;
        $base = $this->executor();
        try {
            $this->recovery(new DoctrineDeploymentInventory($this->first), static function (array $arguments) use ($base, &$checks): string {
                if ($arguments[0] === 'info' && ++$checks > 2) {
                    return 'other.baander.app';
                }
                return $base($arguments);
            })->recover(self::NAMESPACE, self::BOOT);
            self::fail('A changed daemon cannot supply containment.');
        } catch (\RuntimeException) {
            self::assertSame(3, $checks);
        }
        $this->assertReserved($old);
    }

    public function testLostRemovalReplyLeavesCommittedIntentAndEmptyListingCompletesOnlyThatUnit(): void
    {
        $old = $this->registeredOwner();
        $base = $this->executor();
        $failure = new \RuntimeException('Docker removal reply was lost');
        try {
            $this->recovery(new DoctrineDeploymentInventory($this->first), static function (array $arguments) use ($base, $failure): string {
                if ($arguments[0] === 'container' && $arguments[1] === 'rm') {
                    throw $failure;
                }
                return $base($arguments);
            })->recover(self::NAMESPACE, self::BOOT);
            self::fail('Lost reply cannot complete recovery.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        $this->assertReserved($old);
        $execution = (new DoctrineDeploymentRetirement($this->second))->findUnit(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution);
        self::assertNotNull($execution);
        self::assertFalse($execution->completed);
        $executionCalls = [];
        $retry = function (array $arguments) use ($base, &$executionCalls): string {
            if (in_array($this->id(DeploymentUnit::Execution), $arguments, true) || in_array('id=' . $this->id(DeploymentUnit::Execution), $arguments, true)) {
                $executionCalls[] = $arguments;
                self::assertSame('ls', $arguments[1], 'A durable pending proof needs only exact-ID absence.');
                return '';
            }
            return $base($arguments);
        };
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), $retry)->recover(self::NAMESPACE, self::BOOT));
        self::assertCount(1, $executionCalls);
        self::assertNull((new DoctrineDeploymentLease($this->second))->findForContainment(self::NAMESPACE));
    }

    public function testAbsenceWithoutVerifiedUnitIntentNeverCountsAsRetirement(): void
    {
        $old = $this->registeredOwner();
        $calls = 0;
        try {
            $this->recovery(new DoctrineDeploymentInventory($this->first), static function (array $arguments) use (&$calls): string {
                ++$calls;
                if ($arguments[0] === 'info') {
                    return self::DAEMON;
                }
                self::assertSame('inspect', $arguments[1]);
                return '';
            })->recover(self::NAMESPACE, self::BOOT);
            self::fail('Absent unverified container cannot prove containment.');
        } catch (\RuntimeException) {
            self::assertSame(2, $calls);
        }
        $this->assertReserved($old);
        self::assertNull((new DoctrineDeploymentRetirement($this->second))->findUnit(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
    }

    public function testNonisolatedContainerNeverCreatesUnitIntentOrAttemptsRemoval(): void
    {
        $old = $this->registeredOwner();
        $base = $this->executor();
        try {
            $this->recovery(new DoctrineDeploymentInventory($this->first), static function (array $arguments) use ($base): string {
                self::assertFalse($arguments[0] === 'container' && $arguments[1] === 'rm');
                $response = $base($arguments);
                if ($arguments[0] === 'container' && $arguments[1] === 'inspect') {
                    $inspection = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
                    $inspection['pidMode'] = 'host';
                    return json_encode($inspection, JSON_THROW_ON_ERROR);
                }
                return $response;
            })->recover(self::NAMESPACE, self::BOOT);
            self::fail('Host PID namespace cannot authorize removal.');
        } catch (\RuntimeException) {
            $this->assertReserved($old);
        }
        self::assertNull((new DoctrineDeploymentRetirement($this->second))->findUnit(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('units')]
    public function testOneCompletedUnitCannotReleaseOwnershipAndRecoveryResumesOtherUnit(DeploymentUnit $completed): void
    {
        $old = $this->registeredOwner();
        $retirement = new DoctrineDeploymentRetirement($this->first);
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        $binding = new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, $this->id($completed), $completed);
        self::assertTrue($retirement->beginUnitRetirement($binding));
        self::assertTrue($retirement->completeUnitRetirement($binding));
        self::assertFalse($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
        $this->assertReserved($old);
        $base = $this->executor();
        self::assertTrue($this->recovery(new DoctrineDeploymentInventory($this->first), function (array $arguments) use ($base, $completed): string {
            self::assertNotContains($this->id($completed), $arguments, 'Completed units must not be re-inspected or removed.');
            self::assertNotContains('id=' . $this->id($completed), $arguments);
            return $base($arguments);
        })->recover(self::NAMESPACE, self::BOOT));
        self::assertNull((new DoctrineDeploymentLease($this->second))->findForContainment(self::NAMESPACE));
    }

    /** @return iterable<string,array{DeploymentUnit}> */
    public static function units(): iterable
    {
        foreach (DeploymentUnit::cases() as $unit) {
            yield $unit->value . ' removed first' => [$unit];
        }
    }

    public function testNeverClaimedExecutionCanBeCancelledWhenControlIsContained(): void
    {
        $this->reserve();
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control));
        self::assertTrue($inventory->register(new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, $this->id(DeploymentUnit::Control), DeploymentUnit::Control)));
        self::assertTrue($this->recovery($inventory, $this->executor())->recover(self::NAMESPACE, self::BOOT));
        self::assertTrue($inventory->findBoot(self::NAMESPACE, self::BOOT)->execution->cancelled);
        self::assertTrue($inventory->findBoot(self::NAMESPACE, self::BOOT)->retired);
    }

    public function testClaimedUnboundExecutionRemainsReservedWhenNameCannotBeInspected(): void
    {
        $this->reserve();
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
        try {
            $this->recovery($inventory, static function (array $arguments): string {
                if ($arguments[0] === 'info') {
                    return self::DAEMON;
                }
                self::assertSame('inspect', $arguments[1]);
                return '';
            })->recover(self::NAMESPACE, self::BOOT);
            self::fail('Claimed but unbound execution cannot be cancelled through absence.');
        } catch (\RuntimeException) {
            $boot = (new DoctrineDeploymentInventory($this->second))->findBoot(self::NAMESPACE, self::BOOT);
            self::assertNotNull($boot);
            self::assertFalse($boot->retired);
            self::assertFalse($boot->execution->cancelled);
            self::assertFalse($inventory->reserveBoot(self::NAMESPACE, str_repeat('b', 32), str_repeat('f', 64), self::DAEMON, str_repeat('1', 64), str_repeat('2', 64)));
        }
    }

    private const string NAMESPACE = 'baander.app:workers';
    private const string BOOT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string DAEMON = 'baander.app-daemon';

    private function recipe(DeploymentUnit $unit, string $ipcVolume = 'worker-ipc'): \App\Shared\Infrastructure\Worker\DeploymentContainerRecipe
    {
        return new \App\Shared\Infrastructure\Worker\DeploymentContainerRecipe(self::NAMESPACE, self::BOOT, self::DAEMON, 'sha256:' . str_repeat('f', 64), ['/usr/local/bin/php', '/app/bin/worker.php'], 'none', 67108864, 1000000000, 32, $unit, $unit === DeploymentUnit::Control ? [0] : [1], $ipcVolume);
    }

    /** @return array{control:\App\Shared\Infrastructure\Worker\DeploymentContainerRecipe,execution:\App\Shared\Infrastructure\Worker\DeploymentContainerRecipe} */
    private function recipes(): array
    {
        return ['control' => $this->recipe(DeploymentUnit::Control), 'execution' => $this->recipe(DeploymentUnit::Execution)];
    }

    private function reserve(): void
    {
        $recipes = $this->recipes();
        self::assertTrue((new DoctrineDeploymentInventory($this->first))->reserveBoot(self::NAMESPACE, self::BOOT, str_repeat('f', 64), self::DAEMON, $recipes['control']->fingerprint(), $recipes['execution']->fingerprint()));
    }

    private function reserveAndBind(): void
    {
        $this->reserve();
        $inventory = new DoctrineDeploymentInventory($this->first);
        foreach (DeploymentUnit::cases() as $unit) {
            self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, $unit));
            self::assertTrue($inventory->register(new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, $this->id($unit), $unit)));
        }
    }

    private function registeredOwner(): DeploymentLease
    {
        $this->reserveAndBind();
        $binding = new DeploymentContainer(self::NAMESPACE, self::BOOT, self::DAEMON, $this->id(DeploymentUnit::Control), DeploymentUnit::Control);
        self::assertTrue((new DoctrineDeploymentInventory($this->first))->claimStart($binding));
        $lease = (new DoctrineDeploymentLease($this->first))->acquire(self::NAMESPACE, self::BOOT, 60);
        self::assertNotNull($lease);
        return $lease;
    }

    private function assertReserved(DeploymentLease $lease): void
    {
        $leases = new DoctrineDeploymentLease($this->second);
        self::assertEquals($lease, $leases->findForContainment(self::NAMESPACE));
        self::assertNull($leases->acquire(self::NAMESPACE, str_repeat('b', 32), 60));
        self::assertFalse((new DoctrineDeploymentInventory($this->second))->findBoot(self::NAMESPACE, self::BOOT)->retired);
    }

    private function recovery(DoctrineDeploymentInventory $inventory, \Closure $execute): RegisteredDeploymentRecovery
    {
        return new RegisteredDeploymentRecovery($inventory, new DoctrineDeploymentRetirement($this->first), $execute, $this->recipes());
    }

    private function id(DeploymentUnit $unit): string
    {
        return str_repeat($unit === DeploymentUnit::Control ? 'c' : 'd', 64);
    }

    /** @return \Closure(list<string>):string */
    private function executor(): \Closure
    {
        return function (array $arguments): string {
            if ($arguments[0] === 'info') {
                return self::DAEMON;
            }
            if ($arguments[0] === 'volume') {
                return json_encode(['name' => 'worker-ipc', 'driver' => 'local', 'options' => null, 'scope' => 'local', 'mountpoint' => '/var/lib/docker/volumes/worker-ipc/_data'], JSON_THROW_ON_ERROR);
            }
            $target = $arguments[array_key_last($arguments)];
            if ($arguments[1] === 'ls') {
                $target = substr($arguments[5], 3);
            }
            self::assertContains($target, [$this->id(DeploymentUnit::Control), $this->id(DeploymentUnit::Execution)], 'Only exact immutable IDs may be addressed.');
            if ($arguments[1] !== 'inspect') {
                return $target;
            }
            $unit = $target === $this->id(DeploymentUnit::Control) ? DeploymentUnit::Control : DeploymentUnit::Execution;
            $recipe = $this->recipe($unit);
            return json_encode(['id' => $target, 'namespace' => self::NAMESPACE, 'bootId' => self::BOOT, 'role' => $unit->value,
                'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private', 'binds' => null, 'tmpfs' => null,
                'devices' => [], 'deviceRequests' => [], 'capAdd' => null, 'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges'],
                'memory' => $recipe->memoryBytes, 'memorySwap' => $recipe->memoryBytes, 'nanoCpus' => $recipe->nanoCpus, 'pidsLimit' => $recipe->pidsLimit, 'cpuSet' => implode(',', $recipe->cpuSet), 'restart' => 'no', 'retryCount' => 0,
                'mounts' => [['Type' => 'volume', 'Name' => 'worker-ipc', 'Driver' => 'local', 'Source' => '/var/lib/docker/volumes/worker-ipc/_data', 'Destination' => '/run/baander-worker', 'RW' => true]],
            ], JSON_THROW_ON_ERROR);
        };
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
