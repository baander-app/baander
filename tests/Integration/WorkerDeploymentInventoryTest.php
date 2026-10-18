<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual committed PostgreSQL reservations and independently observed claims. */
final class WorkerDeploymentInventoryTest extends TestCase
{
    private Connection $first;
    private InventoryCommitThenThrowConnection $second;
    private string $schema;
    private const string NAMESPACE = 'baander.app:workers';
    private const string BOOT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($params);
        $params['wrapperClass'] = InventoryCommitThenThrowConnection::class;
        $second = DriverManager::getConnection($params);
        self::assertInstanceOf(InventoryCommitThenThrowConnection::class, $second);
        $this->second = $second;
        $this->schema = 'deployment_inventory_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        FinalSchemaBootstrap::bootstrap($this->first);
    }

    public function testReservationCommitsExactlyTwoUnitsAndDeniesAnotherBootBeforeAnyLease(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($this->reserve($inventory));
        self::assertTrue($this->reserve(new DoctrineDeploymentInventory($this->second)));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertSame(['control', 'execution'], $this->second->fetchFirstColumn('SELECT unit FROM worker_deployment_creations ORDER BY unit'));
        $boot = $inventory->findBoot(self::NAMESPACE, self::BOOT);
        self::assertNotNull($boot);
        self::assertFalse($boot->control->createClaimed);
        self::assertFalse($boot->execution->cancelled);
        self::assertFalse($inventory->reserveBoot(self::NAMESPACE, str_repeat('b', 32), str_repeat('f', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64)));
        self::assertFalse($inventory->reserveBoot(self::NAMESPACE, self::BOOT, str_repeat('e', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64)));
        self::assertFalse($inventory->reserveBoot(self::NAMESPACE, self::BOOT, str_repeat('f', 64), 'baander.app:foreign', str_repeat('1', 64), str_repeat('2', 64)));
        self::assertFalse($inventory->reserveBoot(self::NAMESPACE, self::BOOT, str_repeat('f', 64), 'baander.app:daemon', str_repeat('3', 64), str_repeat('2', 64)));
    }

    public function testCreateRequiresReservationAndExactCommittedRecipeObservationDoesNotGrantAnotherAttempt(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertFalse($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control));
        self::assertTrue($this->reserve($inventory));
        self::assertFalse($inventory->matchesCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control, 'baander.app:daemon', str_repeat('1', 64)));
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control));
        self::assertTrue($inventory->matchesCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control, 'baander.app:daemon', str_repeat('1', 64)));
        self::assertFalse($inventory->matchesCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution, 'baander.app:daemon', str_repeat('1', 64)));
        self::assertFalse($inventory->matchesCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control, 'baander.app:foreign', str_repeat('1', 64)));
        self::assertFalse($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control));
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
    }

    public function testBindingRequiresClaimCannotRebindOrReuseSiblingIdAndStartRequiresCompletePair(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($this->reserve($inventory));
        $control = $this->binding();
        self::assertFalse($inventory->register($control));
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control));
        self::assertTrue($inventory->register($control));
        $timestamp = $this->second->fetchOne('SELECT created_at FROM worker_deployment_containers');
        self::assertTrue($inventory->register($control));
        self::assertSame($timestamp, $this->second->fetchOne('SELECT created_at FROM worker_deployment_containers'));
        self::assertFalse($inventory->claimStart($control));
        self::assertFalse($inventory->register(new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:foreign', str_repeat('c', 64), DeploymentUnit::Control)));
        self::assertFalse($inventory->register(new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:daemon', str_repeat('e', 64), DeploymentUnit::Control)));
        self::assertTrue($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Execution));
        self::assertFalse($inventory->register(new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:daemon', str_repeat('c', 64), DeploymentUnit::Execution)));
        self::assertTrue($inventory->register($this->binding(DeploymentUnit::Execution)));
        self::assertFalse($inventory->hasStartClaim($control));
        $leases = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($this->second);
        self::assertNull($leases->acquire(self::NAMESPACE, self::BOOT, 60));
        self::assertTrue($inventory->claimStart($control));
        self::assertNotNull($leases->acquire(self::NAMESPACE, self::BOOT, 60), 'Control claims authority before execution starts.');
        self::assertTrue((new DoctrineDeploymentInventory($this->second))->hasStartClaim($control));
        self::assertFalse($inventory->claimStart($control));
        self::assertTrue($inventory->claimStart($this->binding(DeploymentUnit::Execution)));
    }

    public function testForeignStartAttemptsCannotConsumeAnotherUnitsPermissionOrObserveItsClaim(): void
    {
        [$control, $execution] = \App\Tests\Support\DeploymentJournalFixture::ready($this->first, self::NAMESPACE, self::BOOT);
        $inventory = new DoctrineDeploymentInventory($this->second);
        foreach ([
            new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:foreign', $execution->containerId, DeploymentUnit::Execution),
            new DeploymentContainer(self::NAMESPACE, self::BOOT, $execution->daemonId, str_repeat('e', 64), DeploymentUnit::Execution),
            new DeploymentContainer(self::NAMESPACE, self::BOOT, $control->daemonId, $control->containerId, DeploymentUnit::Execution),
            new DeploymentContainer(self::NAMESPACE, str_repeat('b', 32), $execution->daemonId, $execution->containerId, DeploymentUnit::Execution),
            new DeploymentContainer('baander.app:foreign', self::BOOT, $execution->daemonId, $execution->containerId, DeploymentUnit::Execution),
        ] as $foreign) {
            self::assertFalse($inventory->claimStart($foreign));
            self::assertFalse($inventory->hasStartClaim($foreign));
        }
        self::assertTrue($inventory->claimStart($execution));
        self::assertTrue($inventory->hasStartClaim($execution));
        self::assertFalse($inventory->claimStart($execution));
    }

    /** @param 'reserve'|'create'|'register'|'start' $operation */
    #[DataProvider('uncertainOperations')]
    public function testLostCommitAcknowledgmentNeverRestoresPermission(string $operation): void
    {
        $observer = new DoctrineDeploymentInventory($this->first);
        if ($operation !== 'reserve') {
            self::assertTrue($this->reserve($observer));
        }
        if (in_array($operation, ['register', 'start'], true)) {
            foreach (DeploymentUnit::cases() as $unit) {
                self::assertTrue($observer->claimCreate(self::NAMESPACE, self::BOOT, $unit));
                if ($operation === 'start' || $unit === DeploymentUnit::Execution) {
                    self::assertTrue($observer->register($this->binding($unit)));
                }
            }
        }
        $writer = new DoctrineDeploymentInventory($this->second);
        $this->second->throwAfterCommit = true;
        try {
            match ($operation) {
                'reserve' => $this->reserve($writer),
                'create' => $writer->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control),
                'register' => $writer->register($this->binding()),
                'start' => $writer->claimStart($this->binding()),
            };
            self::fail('Lost commit acknowledgment must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Inventory commit acknowledgment is uncertain.', $error->getMessage());
        }
        self::assertFalse($this->second->isConnected());
        $boot = $observer->findBoot(self::NAMESPACE, self::BOOT);
        self::assertNotNull($boot);
        match ($operation) {
            'reserve' => self::assertTrue($this->reserve($observer)),
            'create' => self::assertFalse($observer->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control)),
            'register' => self::assertTrue($observer->register($this->binding())),
            'start' => self::assertFalse($observer->claimStart($this->binding())),
        };
    }

    /** @return iterable<string, array{'reserve'|'create'|'register'|'start'}> */
    public static function uncertainOperations(): iterable
    {
        foreach (['reserve', 'create', 'register', 'start'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testOuterTransactionIsRejectedWithoutCommittingIt(): void
    {
        $this->first->beginTransaction();
        try {
            $this->reserve(new DoctrineDeploymentInventory($this->first));
            self::fail('An outer transaction must not hide a durable reservation.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('idle autocommit', $error->getMessage());
        }
        self::assertSame(1, $this->first->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_boots'));
    }

    public function testMissingUnitNeverGrantsCreateLeaseOrSuccessfulContainment(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($this->reserve($inventory));
        $this->first->executeStatement("DELETE FROM worker_deployment_creations WHERE unit = 'execution'");
        self::assertFalse($inventory->claimCreate(self::NAMESPACE, self::BOOT, DeploymentUnit::Control));
        self::assertNull((new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($this->second))->acquire(self::NAMESPACE, self::BOOT, 60));
        $retirement = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement($this->second);
        self::assertTrue($retirement->requestRetirement(self::NAMESPACE, self::BOOT));
        self::assertFalse($retirement->completeBootRetirement(self::NAMESPACE, self::BOOT));
        $this->expectException(\UnexpectedValueException::class);
        $inventory->findBoot(self::NAMESPACE, self::BOOT);
    }

    #[DataProvider('invalidStoredStates')]
    public function testDatabaseRejectsInvalidUnitRecipeStateAndForeignBinding(string $sql, string $state): void
    {
        self::assertTrue($this->reserve(new DoctrineDeploymentInventory($this->first)));
        try {
            $this->first->executeStatement($sql);
            self::fail('The database must reject invalid journal state independently of PHP validation.');
        } catch (DriverException $error) {
            self::assertSame($state, $error->getSQLState());
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidStoredStates(): iterable
    {
        yield 'unknown unit' => ["UPDATE worker_deployment_creations SET unit = 'other' WHERE unit = 'control'", '23514'];
        yield 'missing recipe' => ["UPDATE worker_deployment_creations SET recipe_hash = NULL WHERE unit = 'control'", '23514'];
        yield 'cancelled create claim' => ["UPDATE worker_deployment_creations SET claimed_at = clock_timestamp(), cancelled_at = clock_timestamp() WHERE unit = 'control'", '23514'];
        yield 'invalid recipe hash' => ["UPDATE worker_deployment_creations SET recipe_hash = 'invalid' WHERE unit = 'control'", '23514'];
        yield 'foreign daemon binding' => ["INSERT INTO worker_deployment_containers (namespace,boot_id,unit,daemon_id,container_id) SELECT namespace,boot_id,unit,'baander.app:foreign',repeat('c',64) FROM worker_deployment_creations WHERE unit = 'control'", '23503'];
        yield 'missing immutable binding proof' => ["INSERT INTO worker_deployment_retirements (namespace,boot_id,unit,daemon_id,container_id) SELECT namespace,boot_id,unit,daemon_id,repeat('c',64) FROM worker_deployment_creations WHERE unit = 'control'", '23503'];
    }

    public function testJournalInstantsHaveTimeZonesAndUnrestrictedPrecision(): void
    {
        $columns = $this->first->fetchAllAssociative(<<<'SQL'
            SELECT c.relname, a.attname, format_type(a.atttypid,a.atttypmod) AS type
            FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = :schema AND c.relname LIKE 'worker_deployment_%' AND a.attname LIKE '%\_at' ESCAPE '\'
            SQL, ['schema' => $this->schema]);
        self::assertNotEmpty($columns);
        foreach ($columns as $column) {
            self::assertSame('timestamp with time zone', $column['type'], $column['relname'] . '.' . $column['attname']);
        }
    }

    #[DataProvider('concurrentOperations')]
    public function testIndependentProcessesCannotDuplicateReservationCreateOrStart(string $operation): void
    {
        $this->assertConcurrentClaims($operation);
    }

    /** @return iterable<string,array{string}> */
    public static function concurrentOperations(): iterable
    {
        foreach (['reserve', 'create', 'start'] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function assertConcurrentClaims(string $action): void
    {
        if ($action === 'start') {
            \App\Tests\Support\DeploymentJournalFixture::ready($this->first, self::NAMESPACE, self::BOOT);
        } elseif ($action === 'create') {
            self::assertTrue($this->reserve(new DoctrineDeploymentInventory($this->first)));
        }
        $gate = sys_get_temp_dir() . '/baander-start-claim-' . bin2hex(random_bytes(12));
        $code = <<<'PHP'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2] . ', public');
file_put_contents($argv[3].'.ready', 'ready');
$deadline = microtime(true) + 3;
while (!is_file($argv[4])) { if (microtime(true) > $deadline) { exit(2); } usleep(1000); }
$binding = new \App\Shared\Infrastructure\Worker\DeploymentContainer('baander.app:workers', str_repeat('a', 32), 'baander.app:daemon', hash('sha256', 'baander.app:workers' . str_repeat('a', 32) . 'execution'), \App\Shared\Application\DTO\DeploymentUnit::Execution);
$inventory = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($connection);
$permission = match ($argv[5]) {
    'start' => $inventory->claimStart($binding),
    'create' => $inventory->claimCreate('baander.app:workers', str_repeat('a',32), \App\Shared\Application\DTO\DeploymentUnit::Control),
    'reserve' => $inventory->reserveBoot('baander.app:workers', str_repeat($argv[6] === '0' ? 'a' : 'b',32), str_repeat('f',64), 'baander.app:daemon', str_repeat('1',64), str_repeat('2',64)),
};
echo json_encode($permission);
PHP;
        $processes = [];
        $outputs = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $output = tmpfile();
                self::assertIsResource($output);
                $outputs[] = $output;
                $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $gate . $i, $gate, $action, (string) $i], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
                self::assertIsResource($process);
                $processes[] = $process;
            }
            $deadline = microtime(true) + 3;
            while (!is_file($gate . '0.ready') || !is_file($gate . '1.ready')) {
                if (microtime(true) > $deadline) {
                    self::fail('Both independent PostgreSQL contenders must become ready.');
                }
                usleep(1000);
            }
            file_put_contents($gate, 'start');
            $results = [];
            foreach ($processes as $index => $process) {
                while (proc_get_status($process)['running']) {
                    if (microtime(true) > $deadline) {
                        self::fail('Concurrent claim did not complete within the fixture deadline.');
                    }
                    usleep(1000);
                }
                self::assertSame(0, proc_close($process));
                $results[] = json_decode(file_get_contents(stream_get_meta_data($outputs[$index])['uri']), true, flags: JSON_THROW_ON_ERROR);
            }
            sort($results);
            self::assertSame([false, true], $results);
            self::assertSame($action === 'reserve' ? 1 : 2, (int) $this->second->fetchOne($action === 'reserve' ? 'SELECT count(*) FROM worker_deployment_boots' : 'SELECT count(*) FROM worker_deployment_creations'));
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process, 9);
                    proc_close($process);
                }
            }
            foreach ($outputs as $output) {
                fclose($output);
            }
            foreach ([$gate, $gate . '0.ready', $gate . '1.ready'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function reserve(DoctrineDeploymentInventory $inventory): bool
    {
        return $inventory->reserveBoot(self::NAMESPACE, self::BOOT, str_repeat('f', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64));
    }

    private function binding(DeploymentUnit $unit = DeploymentUnit::Control): DeploymentContainer
    {
        return new DeploymentContainer(self::NAMESPACE, self::BOOT, 'baander.app:daemon', str_repeat($unit === DeploymentUnit::Control ? 'c' : 'd', 64), $unit);
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
        if (isset($this->first)) {
            $this->first->close();
        }
        if (isset($this->second)) {
            $this->second->close();
        }
    }
    public function testControlRemovalCannotReleaseExecutionAndClaimedUnboundSiblingRemainsReserved(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        $retirement = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement($this->second);
        $namespace = 'baander.app:paired';
        $boot = str_repeat('a', 32);
        self::assertTrue($inventory->reserveBoot($namespace, $boot, str_repeat('f', 64), 'baander.app-daemon', str_repeat('1', 64), str_repeat('2', 64)));
        foreach (\App\Shared\Application\DTO\DeploymentUnit::cases() as $unit) {
            self::assertTrue($inventory->claimCreate($namespace, $boot, $unit));
        }
        $control = new DeploymentContainer($namespace, $boot, 'baander.app-daemon', str_repeat('c', 64), \App\Shared\Application\DTO\DeploymentUnit::Control);
        self::assertTrue($inventory->register($control));
        self::assertTrue($retirement->requestRetirement($namespace, $boot));
        self::assertTrue($retirement->beginUnitRetirement($control));
        self::assertTrue($retirement->completeUnitRetirement($control));
        self::assertFalse($retirement->completeBootRetirement($namespace, $boot), 'An uncertain execution create cannot be cancelled or inferred absent.');
        self::assertFalse($inventory->reserveBoot($namespace, str_repeat('b', 32), str_repeat('f', 64), 'baander.app-daemon', str_repeat('1', 64), str_repeat('2', 64)));
        $execution = new DeploymentContainer($namespace, $boot, 'baander.app-daemon', str_repeat('d', 64), \App\Shared\Application\DTO\DeploymentUnit::Execution);
        self::assertFalse($inventory->register($execution));
        self::assertTrue($inventory->registerForRetirement($execution));
        self::assertTrue($retirement->beginUnitRetirement($execution));
        self::assertTrue($retirement->completeUnitRetirement($execution));
        self::assertTrue($retirement->completeBootRetirement($namespace, $boot));
        self::assertTrue($inventory->reserveBoot($namespace, str_repeat('b', 32), str_repeat('f', 64), 'baander.app-daemon', str_repeat('1', 64), str_repeat('2', 64)));
    }

}

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
