<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use App\Tests\Support\DeploymentJournalFixture;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerDeploymentRetirementTest extends TestCase
{
    private Connection $connection;
    private RetirementBarrierConnection $writer;
    private string $schema;
    private const string NAMESPACE = 'baander.app:retirement';
    private const string BOOT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $contenderNamespace = self::NAMESPACE;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->connection = DriverManager::getConnection($params);
        $params['wrapperClass'] = RetirementBarrierConnection::class;
        $writer = DriverManager::getConnection($params);
        self::assertInstanceOf(RetirementBarrierConnection::class, $writer);
        $this->writer = $writer;
        $this->schema = 'worker_retirement_test_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->connection, $this->writer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        FinalSchemaBootstrap::bootstrap($this->connection);
        DeploymentJournalFixture::ready($this->connection, self::NAMESPACE, self::BOOT);
    }

    private function binding(DeploymentUnit $unit = DeploymentUnit::Control): DeploymentContainer
    {
        return new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:daemon', hash('sha256', self::NAMESPACE . self::BOOT . $unit->value), $unit);
    }

    #[DataProvider('removalOrders')]
    public function testEitherRemovalOrderKeepsLeaseUntilBothProofsAndRootCompletion(DeploymentUnit $first, DeploymentUnit $second): void
    {
        $leases = new DoctrineDeploymentLease($this->connection);
        $lease = $leases->acquire(self::NAMESPACE, self::BOOT, 60);
        self::assertNotNull($lease);
        $retirement = new DoctrineDeploymentRetirement($this->writer);
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        self::assertFalse($leases->renew($lease, 60));
        foreach ([$first, $second] as $unit) {
            $binding = $this->binding($unit);
            self::assertFalse($retirement->completeUnitRetirement($binding));
            self::assertTrue($retirement->beginUnitRetirement($binding));
            self::assertFalse($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
            self::assertTrue($retirement->completeUnitRetirement($binding));
            self::assertTrue($retirement->completeUnitRetirement($binding));
            self::assertEquals($lease, $leases->findForContainment(self::NAMESPACE));
            if ($unit === $first) {
                self::assertFalse($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
            }
        }
        self::assertTrue($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
        self::assertNull($leases->findForContainment(self::NAMESPACE));
        self::assertNull($leases->acquire(self::NAMESPACE, self::BOOT, 60));
        DeploymentJournalFixture::ready($this->connection, self::NAMESPACE, str_repeat('b', 32));
        $next = $leases->acquire(self::NAMESPACE, str_repeat('b', 32), 60);
        self::assertNotNull($next);
        self::assertSame(2, $next->epoch);
        self::assertTrue($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
        self::assertEquals($next, $leases->findForContainment(self::NAMESPACE));
    }

    /** @return iterable<string,array{DeploymentUnit,DeploymentUnit}> */
    public static function removalOrders(): iterable
    {
        yield 'control first' => [DeploymentUnit::Control, DeploymentUnit::Execution];
        yield 'execution first' => [DeploymentUnit::Execution, DeploymentUnit::Control];
    }

    public function testPreleaseTombstoneDeniesEveryAdmissionAndWrongBindingCannotSupplyProof(): void
    {
        $retirement = new DoctrineDeploymentRetirement($this->writer);
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        $inventory = new DoctrineDeploymentInventory($this->connection);
        self::assertNull((new DoctrineDeploymentLease($this->connection))->acquire(self::NAMESPACE, self::BOOT, 60));
        self::assertFalse($inventory->claimStart($this->binding(DeploymentUnit::Execution)));
        foreach (DeploymentUnit::cases() as $unit) {
            self::assertFalse($inventory->claimCreate(self::NAMESPACE, self::BOOT, $unit));
        }
        $wrong = new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:foreign', str_repeat('d', 64), DeploymentUnit::Control);
        self::assertFalse($retirement->beginUnitRetirement($wrong));
        self::assertFalse($retirement->completeUnitRetirement($wrong));
        self::assertFalse($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
    }

    public function testUnclaimedUnitCancellationAllowsPartialBootRetirementButClaimedUnboundDoesNot(): void
    {
        $namespace = 'baander.app:partial';
        $inventory = new DoctrineDeploymentInventory($this->connection);
        self::assertTrue($inventory->reserveBoot($namespace, self::BOOT, str_repeat('f', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64)));
        self::assertTrue($inventory->claimCreate($namespace, self::BOOT, DeploymentUnit::Control));
        $binding = new DeploymentContainer($namespace, self::BOOT, 'baander.app:daemon', str_repeat('e', 64), DeploymentUnit::Control);
        self::assertTrue($inventory->register($binding));
        $retirement = new DoctrineDeploymentRetirement($this->writer);
        self::assertTrue($retirement->requestRetirement($namespace, self::BOOT));
        $boot = $inventory->findBoot($namespace, self::BOOT);
        self::assertNotNull($boot);
        self::assertTrue($boot->execution->cancelled);
        self::assertFalse($boot->control->cancelled);
        self::assertFalse($retirement->completeBootRetirement($namespace, self::BOOT));
        self::assertTrue($retirement->beginUnitRetirement($binding));
        self::assertTrue($retirement->completeUnitRetirement($binding));
        self::assertTrue($retirement->completeBootRetirement($namespace, self::BOOT));
    }

    /** @param 'request'|'begin'|'unit'|'boot' $operation */
    #[DataProvider('uncertainOperations')]
    public function testActualCommitWithLostAcknowledgmentIsReconciled(string $operation): void
    {
        $leases = new DoctrineDeploymentLease($this->connection);
        $lease = $leases->acquire(self::NAMESPACE, self::BOOT, 60);
        self::assertNotNull($lease);
        $retirement = new DoctrineDeploymentRetirement($this->connection);
        if ($operation !== 'request') {
            self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        }
        if (in_array($operation, ['unit', 'boot'], true)) {
            foreach (DeploymentUnit::cases() as $unit) {
                self::assertTrue($retirement->beginUnitRetirement($this->binding($unit)));
                if ($operation === 'boot') {
                    self::assertTrue($retirement->completeUnitRetirement($this->binding($unit)));
                }
            }
        }
        $this->writer->throwAfterCommit = true;
        $writer = new DoctrineDeploymentRetirement($this->writer);
        try {
            $this->apply($writer, $operation);
            self::fail('Unknown commit acknowledgment must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected retirement commit uncertainty.', $error->getMessage());
        }
        self::assertFalse($this->writer->isConnected());
        $boot = (new DoctrineDeploymentInventory($this->connection))->findBoot(self::NAMESPACE, self::BOOT);
        self::assertNotNull($boot);
        self::assertTrue($boot->retirementRequested);
        self::assertSame($operation === 'boot', $boot->retired);
        self::assertTrue($this->apply($retirement, $operation));
        self::assertSame($operation === 'boot', $leases->findForContainment(self::NAMESPACE) === null);
    }

    /** @param 'request'|'begin'|'unit'|'boot' $operation */
    private function apply(DoctrineDeploymentRetirement $retirement, string $operation): bool
    {
        return match ($operation) {
            'request' => $retirement->requestRetirement(self::NAMESPACE, self::BOOT),
            'begin' => $retirement->beginUnitRetirement($this->binding()),
            'unit' => $retirement->completeUnitRetirement($this->binding()),
            'boot' => $retirement->completeBootRetirement(self::NAMESPACE, self::BOOT),
        };
    }

    /** @return iterable<string,array{'request'|'begin'|'unit'|'boot'}> */
    public static function uncertainOperations(): iterable
    {
        foreach (['request', 'begin', 'unit', 'boot'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testFinalReleaseIsAtomicToIndependentObserverAndRollbackRetainsOwnership(): void
    {
        $leases = new DoctrineDeploymentLease($this->connection);
        $lease = $leases->acquire(self::NAMESPACE, self::BOOT, 60);
        self::assertNotNull($lease);
        $retirement = new DoctrineDeploymentRetirement($this->writer);
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        foreach (DeploymentUnit::cases() as $unit) {
            self::assertTrue($retirement->beginUnitRetirement($this->binding($unit)));
            self::assertTrue($retirement->completeUnitRetirement($this->binding($unit)));
        }
        $this->writer->beforeCommit = function () use ($lease): void {
            self::assertEquals($lease, (new DoctrineDeploymentLease($this->connection))->findForContainment(self::NAMESPACE));
            self::assertNull($this->connection->fetchOne('SELECT retired_at FROM worker_deployment_boots'));
            throw new \RuntimeException('Rollback final release.');
        };
        try {
            $retirement->completeBootRetirement(self::NAMESPACE, self::BOOT);
            self::fail('Injected rollback must fail completion.');
        } catch (\RuntimeException $error) {
            self::assertSame('Rollback final release.', $error->getMessage());
        }
        self::assertEquals($lease, $leases->findForContainment(self::NAMESPACE));
        self::assertFalse((new DoctrineDeploymentInventory($this->connection))->findBoot(self::NAMESPACE, self::BOOT)?->retired);
        self::assertTrue((new DoctrineDeploymentRetirement($this->connection))->completeBootRetirement(self::NAMESPACE, self::BOOT));
        self::assertNull($leases->findForContainment(self::NAMESPACE));
    }

    #[DataProvider('contenders')]
    public function testContenderWaitsForRetirementBarrierAndSeesCommittedTombstone(string $operation): void
    {
        if ($operation === 'create') {
            $this->contenderNamespace = 'baander.app:create-race';
            self::assertTrue((new DoctrineDeploymentInventory($this->connection))->reserveBoot($this->contenderNamespace, self::BOOT, str_repeat('f', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64)));
        }
        $this->writer->beforeCommit = function () use ($operation): void {
            $this->startContender($operation);
        };
        self::assertTrue((new DoctrineDeploymentRetirement($this->writer, 5000, 4000))->requestRetirement($this->contenderNamespace, self::BOOT));
        self::assertSame('denied', $this->finishContender());
    }

    /** @return iterable<string,array{string}> */
    public static function contenders(): iterable
    {
        foreach (['acquire', 'start', 'create'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testAcquireWinnerCommitsBeforeRetirementCanAuthorizeRemoval(): void
    {
        $this->writer->beforeCommit = function (): void {
            $this->startContender('retire');
        };
        $lease = (new DoctrineDeploymentLease($this->writer, 5000, 4000))->acquire(self::NAMESPACE, self::BOOT, 60);
        self::assertNotNull($lease);
        self::assertSame('admitted', $this->finishContender());
        self::assertEquals($lease, (new DoctrineDeploymentLease($this->connection))->findForContainment(self::NAMESPACE));
    }

    public function testCreateWinnerIsNotCancelledByWaitingRetirement(): void
    {
        $this->contenderNamespace = 'baander.app:create-race';
        self::assertTrue((new DoctrineDeploymentInventory($this->connection))->reserveBoot($this->contenderNamespace, self::BOOT, str_repeat('f', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64)));
        $this->writer->beforeCommit = function (): void {
            $this->startContender('retire');
        };
        self::assertTrue((new DoctrineDeploymentInventory($this->writer, 5000, 4000))->claimCreate($this->contenderNamespace, self::BOOT, DeploymentUnit::Execution));
        self::assertSame('admitted', $this->finishContender());
        $boot = (new DoctrineDeploymentInventory($this->connection))->findBoot($this->contenderNamespace, self::BOOT);
        self::assertNotNull($boot);
        self::assertTrue($boot->execution->createClaimed);
        self::assertFalse($boot->execution->cancelled);
        self::assertTrue($boot->control->cancelled);
        self::assertFalse((new DoctrineDeploymentRetirement($this->connection))->completeBootRetirement($this->contenderNamespace, self::BOOT));
    }

    /** @var resource|null */
    private $contender = null;
    /** @var resource|null */
    private $output = null;
    private ?string $marker = null;

    private function startContender(string $operation): void
    {
        $this->marker = sys_get_temp_dir() . '/baander-retirement-' . bin2hex(random_bytes(12));
        $this->output = tmpfile();
        $code = <<<'CHILD'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$c = \Doctrine\DBAL\DriverManager::getConnection($params);
$c->executeStatement('SET search_path TO ' . $argv[2] . ', public');
// Prove operation explicitly uses READ COMMITTED despite a stronger session default.
$c->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
file_put_contents($argv[3], (string) $c->fetchOne('SELECT pg_backend_pid()'));
$unit = $argv[4] === 'start' ? \App\Shared\Application\DTO\DeploymentUnit::Execution : \App\Shared\Application\DTO\DeploymentUnit::Control;
$b = new \App\Shared\Infrastructure\Worker\DeploymentContainer($argv[5], str_repeat('a',32), 'baander.app:daemon',hash('sha256', $argv[5] . str_repeat('a',32) . $unit->value), $unit);
$result = match ($argv[4]) {
'acquire' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($c,5000,4000))->acquire($b->namespace,$b->bootId,60) !== null,
'start' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($c,5000,4000))->claimStart($b),
'retire' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement($c,5000,4000))->requestRetirement($b->namespace,$b->bootId),
'create' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($c,5000,4000))->claimCreate($b->namespace,$b->bootId,\App\Shared\Application\DTO\DeploymentUnit::Execution),
};
echo $result ? 'admitted' : 'denied';
CHILD;
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $this->marker, $operation, $this->contenderNamespace], [0 => ['file', '/dev/null', 'r'], 1 => $this->output, 2 => $this->output], $pipes);
        self::assertIsResource($process);
        $this->contender = $process;
        $deadline = microtime(true) + 3;
        do {
            if (is_file($this->marker)) {
                $pid = file_get_contents($this->marker);
                if (is_string($pid) && ctype_digit($pid)
                    && $this->connection->fetchOne("SELECT 1 FROM pg_locks WHERE pid = :pid AND locktype = 'advisory' AND NOT granted", ['pid' => (int) $pid]) !== false) {
                    return;
                }
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        self::fail('Independent PostgreSQL contender did not wait on the namespace barrier.');
    }

    private function finishContender(): string
    {
        self::assertIsResource($this->contender);
        $status = proc_close($this->contender);
        $this->contender = null;
        self::assertIsResource($this->output);
        rewind($this->output);
        $output = stream_get_contents($this->output);
        self::assertSame(0, $status, $output);
        return $output;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->contender)) {
            proc_terminate($this->contender, 9);
            proc_close($this->contender);
        }
        if (is_resource($this->output)) {
            fclose($this->output);
        }
        if ($this->marker !== null && is_file($this->marker)) {
            unlink($this->marker);
        }
        foreach ([$this->connection ?? null, $this->writer ?? null] as $connection) {
            if ($connection !== null) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
        if (isset($this->schema)) {
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->connection ?? null, $this->writer ?? null] as $connection) {
            $connection?->close();
        }
    }
}

/** Real PostgreSQL commits; barriers and lost acknowledgments affect only the controller observation. */
final class RetirementBarrierConnection extends Connection
{
    public ?\Closure $beforeCommit = null;
    public bool $throwAfterCommit = false;

    public function commit(): void
    {
        if ($this->beforeCommit !== null) {
            $callback = $this->beforeCommit;
            $this->beforeCommit = null;
            $callback();
        }
        parent::commit();
        if ($this->throwAfterCommit) {
            $this->throwAfterCommit = false;
            throw new \RuntimeException('Injected retirement commit uncertainty.');
        }
    }
}
