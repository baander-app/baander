<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerDeploymentRetirementTest extends TestCase
{
    private Connection $connection;
    private RetirementBarrierConnection $writer;
    private string $schema;

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
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        foreach (['Version20261002210000', 'Version20261002220000', 'Version20261003020000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = '\\DoctrineMigrations\\' . $version;
            $migration = new $class($this->connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        self::assertTrue((new DoctrineDeploymentInventory($this->connection))->register($this->binding()));
    }

    private function binding(): DeploymentContainer
    {
        return new DeploymentContainer('baander.app:retirement', str_repeat('a', 32), 'baander.app-daemon', str_repeat('c', 64));
    }

    public function testPreleaseRetirementPermanentlyDeniesLeaseAndStartAndCompletesIdempotently(): void
    {
        $binding = $this->binding();
        $retirements = new DoctrineDeploymentRetirement($this->connection);
        self::assertTrue($retirements->begin($binding));
        self::assertTrue($retirements->begin($binding));
        $intent = $retirements->find($binding->namespace, $binding->bootId);
        self::assertNotNull($intent);
        self::assertFalse($intent->completed);
        self::assertNull((new DoctrineDeploymentLease($this->writer))->acquire($binding->namespace, $binding->bootId, 60));
        self::assertFalse((new DoctrineDeploymentInventory($this->writer))->claimStart($binding));
        self::assertTrue($retirements->complete($binding));
        self::assertTrue($retirements->complete($binding));
        $completed = $retirements->find($binding->namespace, $binding->bootId);
        self::assertNotNull($completed);
        self::assertTrue($completed->completed);
        self::assertNull((new DoctrineDeploymentLease($this->writer))->acquire($binding->namespace, $binding->bootId, 60));
        self::assertFalse((new DoctrineDeploymentInventory($this->writer))->claimStart($binding));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM worker_deployment_leases'));
    }

    public function testWrongBindingAndMissingIntentNeverReleaseOwnership(): void
    {
        $binding = $this->binding();
        $leases = new DoctrineDeploymentLease($this->connection);
        $lease = $leases->acquire($binding->namespace, $binding->bootId, 60);
        $retirements = new DoctrineDeploymentRetirement($this->writer);
        self::assertFalse($retirements->complete($binding));
        $wrong = new DeploymentContainer($binding->namespace, $binding->bootId, $binding->daemonId, str_repeat('d', 64));
        self::assertFalse($retirements->begin($wrong));
        self::assertTrue($retirements->begin($binding));
        self::assertFalse($retirements->begin($wrong));
        self::assertFalse($retirements->complete($wrong));
        self::assertEquals($lease, $leases->findForContainment($binding->namespace));
    }

    public function testCompletionReleasesOwnBootAtomicallyAndNeverChangesForeignBoot(): void
    {
        $binding = $this->binding();
        $leases = new DoctrineDeploymentLease($this->connection);
        $old = $leases->acquire($binding->namespace, $binding->bootId, 60);
        self::assertNotNull($old);
        $retirements = new DoctrineDeploymentRetirement($this->writer);
        self::assertTrue($retirements->begin($binding));
        self::assertTrue($retirements->complete($binding));
        self::assertNull($leases->findForContainment($binding->namespace));
        $foreign = $leases->acquire($binding->namespace, str_repeat('b', 32), 60);
        self::assertNotNull($foreign);
        self::assertSame(2, $foreign->epoch);
        self::assertTrue($retirements->complete($binding));
        self::assertEquals($foreign, $leases->findForContainment($binding->namespace));
    }

    public function testCompletionOfPreleaseBootPreservesAlreadyActiveForeignOwner(): void
    {
        $binding = $this->binding();
        $leases = new DoctrineDeploymentLease($this->connection);
        $foreign = $leases->acquire($binding->namespace, str_repeat('b', 32), 60);
        $retirements = new DoctrineDeploymentRetirement($this->writer);
        self::assertTrue($retirements->begin($binding));
        self::assertTrue($retirements->complete($binding));
        self::assertEquals($foreign, $leases->findForContainment($binding->namespace));
    }

    #[DataProvider('uncertainOperations')]
    public function testActualCommitWithLostAcknowledgmentIsReconciled(string $operation): void
    {
        $binding = $this->binding();
        $leases = new DoctrineDeploymentLease($this->connection);
        if ($operation === 'complete') {
            self::assertNotNull($leases->acquire($binding->namespace, $binding->bootId, 60));
            self::assertTrue((new DoctrineDeploymentRetirement($this->connection))->begin($binding));
        }
        $this->writer->throwAfterCommit = true;
        try {
            (new DoctrineDeploymentRetirement($this->writer))->{$operation}($binding);
            self::fail('Unknown commit acknowledgment must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected retirement commit uncertainty.', $error->getMessage());
        }
        self::assertFalse($this->writer->isConnected());
        $retirements = new DoctrineDeploymentRetirement($this->connection);
        $intent = $retirements->find($binding->namespace, $binding->bootId);
        self::assertNotNull($intent);
        self::assertSame($operation === 'complete', $intent->completed);
        self::assertTrue($retirements->{$operation}($binding));
        self::assertNull($leases->acquire($binding->namespace, $binding->bootId, 60));
        if ($operation === 'complete') {
            self::assertNull($leases->findForContainment($binding->namespace));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function uncertainOperations(): iterable
    {
        yield 'intent acknowledgment' => ['begin'];
        yield 'completion acknowledgment' => ['complete'];
    }

    #[DataProvider('contenders')]
    public function testContenderWaitsForCommittedRetirementAndCannotUseAnEarlierSnapshot(string $operation): void
    {
        $this->writer->beforeCommit = function () use ($operation): void {
            $this->startContender($operation);
        };
        self::assertTrue((new DoctrineDeploymentRetirement($this->writer, 5000, 4000))->begin($this->binding()));
        self::assertSame('denied', $this->finishContender());
    }

    /** @return iterable<string, array{string}> */
    public static function contenders(): iterable
    {
        yield 'acquire' => ['acquire'];
        yield 'start' => ['start'];
    }

    public function testRetirementWaitsUntilInFlightAcquireCommitsBeforeItCanAuthorizeRemoval(): void
    {
        $binding = $this->binding();
        $this->writer->beforeCommit = function (): void {
            $this->startContender('begin');
        };
        $lease = (new DoctrineDeploymentLease($this->writer, 5000, 4000))->acquire($binding->namespace, $binding->bootId, 60);
        self::assertNotNull($lease);
        self::assertSame('admitted', $this->finishContender());
        $retirements = new DoctrineDeploymentRetirement($this->connection);
        self::assertNotNull($retirements->find($binding->namespace, $binding->bootId));
        self::assertEquals($lease, (new DoctrineDeploymentLease($this->connection))->findForContainment($binding->namespace));
        self::assertTrue($retirements->complete($binding));
        self::assertNull((new DoctrineDeploymentLease($this->connection))->findForContainment($binding->namespace));
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
$c->executeStatement('SET search_path TO ' . $argv[2]);
// Prove operation explicitly uses READ COMMITTED despite a stronger session default.
$c->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
file_put_contents($argv[3], (string) $c->fetchOne('SELECT pg_backend_pid()'));
$b = new \App\Shared\Infrastructure\Worker\DeploymentContainer('baander.app:retirement', str_repeat('a',32), 'baander.app-daemon',str_repeat('c',64));
$result = match ($argv[4]) {
'acquire' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($c,5000,4000))->acquire($b->namespace,$b->bootId,60) !== null,
'start' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($c,5000,4000))->claimStart($b),
'begin' => (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement($c,5000,4000))->begin($b),
};
echo $result ? 'admitted' : 'denied';
CHILD;
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $this->marker, $operation], [0 => ['file', '/dev/null', 'r'], 1 => $this->output, 2 => $this->output], $pipes);
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
