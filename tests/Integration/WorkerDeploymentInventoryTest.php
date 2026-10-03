<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
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
        foreach (['Version20261002220000', 'Version20261003020000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->first, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
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

    public function testCreationClaimCommitsOnceWithoutContainerBindingAndPreservesTimestamp(): void
    {
        $first = new DoctrineDeploymentInventory($this->first);
        $second = new DoctrineDeploymentInventory($this->second);
        $recipe = $this->recipe();
        self::assertFalse($first->matchesCreate($recipe));
        self::assertTrue($first->claimCreate($recipe));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertTrue($second->matchesCreate($recipe));
        $reservedAt = $this->second->fetchOne('SELECT created_at FROM worker_deployment_creations');
        self::assertNotNull($reservedAt);
        self::assertFalse($second->claimCreate($recipe));
        self::assertFalse($first->claimCreate($recipe));
        self::assertSame($reservedAt, $this->second->fetchOne('SELECT created_at FROM worker_deployment_creations'));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_creations'));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM worker_deployment_containers'), 'An absent or removed unregistered container cannot free the durable creation attempt.');
        self::assertSame('timestamp with time zone', $this->second->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'worker_deployment_creations' AND column_name = 'created_at'", ['schema' => $this->schema]));
    }

    public function testMismatchedCreationRecipeCannotReconcileOrConsumeAnotherAttempt(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        $recipe = $this->recipe();
        self::assertTrue($inventory->claimCreate($recipe));
        foreach ([$this->recipe(daemon: 'baander.app:other-daemon'), $this->recipe(memory: 512 * 1024 * 1024)] as $wrong) {
            self::assertFalse($inventory->matchesCreate($wrong));
            self::assertFalse($inventory->claimCreate($wrong));
        }
        self::assertTrue($inventory->matchesCreate($recipe));
        self::assertSame($recipe->fingerprint(), $this->second->fetchOne('SELECT recipe_hash FROM worker_deployment_creations'));
    }

    public function testUncertainCommittedCreationClaimBurnsPermissionBeforeAnyBindingExists(): void
    {
        $params = $this->params;
        $params['wrapperClass'] = InventoryCommitThenThrowConnection::class;
        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(InventoryCommitThenThrowConnection::class, $connection);
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->throwAfterCommit = true;
        $permission = null;
        try {
            $permission = (new DoctrineDeploymentInventory($connection))->claimCreate($this->recipe());
            self::fail('Uncertain commit acknowledgment must not grant another creation attempt.');
        } catch (\RuntimeException $error) {
            self::assertSame('Inventory commit acknowledgment is uncertain.', $error->getMessage());
        }
        self::assertNull($permission);
        self::assertFalse($connection->isConnected());
        $observer = new DoctrineDeploymentInventory($this->second);
        self::assertTrue($observer->matchesCreate($this->recipe()));
        self::assertFalse($observer->claimCreate($this->recipe()));
        self::assertNull($observer->find($this->recipe()->namespace, $this->recipe()->bootId));
    }

    public function testStartClaimCommitsOnceAndRegistrationRetriesPreserveItsTimestamp(): void
    {
        $first = new DoctrineDeploymentInventory($this->first);
        $second = new DoctrineDeploymentInventory($this->second);
        $binding = $this->binding();
        self::assertTrue($first->register($binding));
        self::assertNull($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers'));
        self::assertTrue($first->claimStart($binding));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        $claimedAt = $this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers');
        self::assertNotNull($claimedAt, 'The start permission is committed before returning true.');
        self::assertFalse($second->claimStart($binding));
        self::assertTrue($first->register($binding));
        self::assertFalse($first->claimStart($binding));
        self::assertSame($claimedAt, $this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers'));
        self::assertSame('timestamp with time zone', $this->second->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'worker_deployment_containers' AND column_name = 'start_claimed_at'", ['schema' => $this->schema]));
    }

    public function testWrongStartBindingCannotConsumePermission(): void
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->register($this->binding()));
        foreach ([
            $this->binding(namespace: 'baander.app:other-workers'),
            $this->binding(boot: str_repeat('b', 32)),
            $this->binding(daemon: 'baander.app:other-daemon'),
            $this->binding(container: str_repeat('d', 64)),
        ] as $wrong) {
            self::assertFalse($inventory->claimStart($wrong));
        }
        self::assertNull($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers'));
        self::assertTrue($inventory->claimStart($this->binding()));
    }

    public function testUncertainCommittedStartClaimCannotBeRetried(): void
    {
        self::assertTrue((new DoctrineDeploymentInventory($this->first))->register($this->binding()));
        $params = $this->params;
        $params['wrapperClass'] = InventoryCommitThenThrowConnection::class;
        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(InventoryCommitThenThrowConnection::class, $connection);
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->throwAfterCommit = true;
        $permission = null;
        try {
            $permission = (new DoctrineDeploymentInventory($connection))->claimStart($this->binding());
            self::fail('Uncertain commit acknowledgment must not supply start permission.');
        } catch (\RuntimeException $error) {
            self::assertSame('Inventory commit acknowledgment is uncertain.', $error->getMessage());
        }
        self::assertNull($permission);
        self::assertFalse($connection->isConnected());
        $claimedAt = $this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers');
        self::assertNotNull($claimedAt);
        $observer = new DoctrineDeploymentInventory($this->second);
        self::assertTrue($observer->register($this->binding()));
        self::assertFalse($observer->claimStart($this->binding()));
        self::assertSame($claimedAt, $this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers'));
    }

    public function testConcurrentFreshProcessesReceiveExactlyOneStartPermission(): void
    {
        $this->assertConcurrentClaims('start');
    }

    public function testConcurrentFreshProcessesReceiveExactlyOneCreationPermission(): void
    {
        $this->assertConcurrentClaims('create');
    }

    private function assertConcurrentClaims(string $action): void
    {
        if ($action === 'start') {
            self::assertTrue((new DoctrineDeploymentInventory($this->first))->register($this->binding()));
        }
        $gate = sys_get_temp_dir() . '/baander-start-claim-' . bin2hex(random_bytes(12));
        $code = <<<'PHP'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2]);
file_put_contents($argv[3].'.ready', 'ready');
$deadline = microtime(true) + 3;
while (!is_file($argv[4])) { if (microtime(true) > $deadline) { exit(2); } usleep(1000); }
$binding = new \App\Shared\Infrastructure\Worker\DeploymentContainer('baander.app:workers', str_repeat('a', 32), 'baander.app:daemon', str_repeat('c', 64));
$inventory = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($connection);
$permission = $argv[5] === 'start' ? $inventory->claimStart($binding) : $inventory->claimCreate(new \App\Shared\Infrastructure\Worker\DeploymentContainerRecipe('baander.app:workers', str_repeat('a', 32), 'baander.app:daemon', 'sha256:'.str_repeat('d', 64), ['/usr/local/bin/php', 'bin/console', 'app:worker'], 'none', 256 * 1024 * 1024, 1_000_000_000, 32));
echo json_encode($permission);
PHP;
        $processes = [];
        $outputs = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $output = tmpfile();
                self::assertIsResource($output);
                $outputs[] = $output;
                $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $gate . $i, $gate, $action], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
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
            self::assertNotNull($this->second->fetchOne($action === 'start' ? 'SELECT start_claimed_at FROM worker_deployment_containers' : 'SELECT created_at FROM worker_deployment_creations'));
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

    private function recipe(string $daemon = 'baander.app:daemon', int $memory = 256 * 1024 * 1024): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe('baander.app:workers', str_repeat('a', 32), $daemon, 'sha256:' . str_repeat('d', 64), ['/usr/local/bin/php', 'bin/console', 'app:worker'], 'none', $memory, 1_000_000_000, 32);
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
