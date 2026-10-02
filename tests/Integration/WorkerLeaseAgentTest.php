<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\LeaseAgentProcess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerLeaseAgentTest extends TestCase
{
    private Connection $connection;
    private string $schema;
    /** @var array<string, string> */
    private array $environment;
    /** @var list<LeaseAgentProcess> */
    private array $agents = [];
    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'lease_agent_test_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema);
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002210000.php';
        $migration = new \DoctrineMigrations\Version20261002210000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->environment = [...getenv(), 'DATABASE_URL' => $url, 'PGOPTIONS' => '-c search_path=' . $this->schema];
    }

    public function testRealHelperAcquiresAndRenewsWithBoundSequenceAndIdentity(): void
    {
        $agent = $this->start($this->request());
        self::assertNull($agent->takeResult(), 'A running/unpolled helper cannot grant admission.');
        $result = $this->finish($agent);
        self::assertSame(['action' => 'acquire', 'sequence' => 1, 'success' => true, 'category' => 'acquired', 'lease' => ['namespace' => 'baander.app:workers', 'bootId' => str_repeat('a', 32), 'epoch' => 1]], $result);
        self::assertNull($agent->takeResult(), 'Results are consumed once.');
        self::assertSame('active', $this->connection->fetchOne('SELECT state FROM worker_deployment_leases'));
        $renew = $this->finish($this->start($this->request('renew', 2, 1)));
        self::assertTrue($renew['success']);
        self::assertSame('renewed', $renew['category']);
        self::assertSame(2, $renew['sequence']);
        self::assertSame($result['lease'], $renew['lease']);
    }

    public function testRealHelperDeniesWrongOwnerExpiredOwnerAndDuplicateAcquisition(): void
    {
        self::assertTrue($this->finish($this->start($this->request()))['success']);
        self::assertSame('denied', $this->finish($this->start($this->request('acquire', 2)))['category']);
        $wrong = $this->request('renew', 3, 1);
        $wrong['bootId'] = str_repeat('b', 32);
        self::assertSame('denied', $this->finish($this->start($wrong))['category']);
        $this->connection->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - interval '1 second'");
        $expired = $this->finish($this->start($this->request('renew', 4, 1)));
        self::assertFalse($expired['success']);
        self::assertNull($expired['lease']);
        self::assertSame('denied', $expired['category']);
        self::assertSame('active', $this->connection->fetchOne('SELECT state FROM worker_deployment_leases'), 'The ordinary helper cannot acknowledge containment or free ownership.');
    }

    public function testUnavailableDatabaseReturnsSanitizedFailure(): void
    {
        $environment = $this->environment;
        $environment['DATABASE_URL'] = 'postgresql://lease-user:do-not-leak-password@127.0.0.1:1/baander_test';
        $result = $this->finish($this->start($this->request(), environment: $environment));
        self::assertFalse($result['success']);
        self::assertSame('database_error', $result['category']);
        self::assertStringNotContainsString('do-not-leak-password', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNull($result['lease']);
    }

    public function testBlockedRealDatabaseHelperTimesOutWithoutGrantingAuthority(): void
    {
        $lease = (new DoctrineDeploymentLease($this->connection))->acquire('baander.app:workers', str_repeat('a', 32), 60);
        self::assertNotNull($lease);
        $expiry = $this->connection->fetchOne('SELECT expires_at FROM worker_deployment_leases');
        $this->connection->beginTransaction();
        $this->connection->executeQuery('SELECT namespace FROM worker_deployment_leases FOR UPDATE')->free();
        try {
            $result = $this->finish($this->start($this->request('renew', 2, 1), timeout: 0.03));
            self::assertSame('timeout', $result['category']);
            self::assertFalse($result['success']);
            self::assertNull($result['lease']);
        } finally {
            $this->connection->rollBack();
        }
        self::assertSame($expiry, $this->connection->fetchOne('SELECT expires_at FROM worker_deployment_leases'));
    }

    public function testCompletedButUnobservedReplyAfterDeadlineIsRejected(): void
    {
        $agent = $this->start($this->request(), timeout: 0.5);
        // An independent observer establishes real commit before any parent poll.
        $deadline = microtime(true) + 0.4;
        while ((int) $this->connection->fetchOne('SELECT count(*) FROM worker_deployment_leases') !== 1) {
            if (microtime(true) > $deadline) {
                self::fail('Real helper did not commit within the fixture observation window.');
            }
            usleep(1000);
        }
        $result = $this->finish($agent, hrtime(true) / 1e9 + 0.6);
        self::assertSame('timeout', $result['category']);
        self::assertFalse($result['success']);
        self::assertNotNull((new DoctrineDeploymentLease($this->connection))->findForContainment('baander.app:workers'), 'A late committed claim remains reserved for trusted-controller reconciliation.');
    }

    public function testCancellationReapsTermIgnoringFreshExecChild(): void
    {
        $directory = $this->fixture('pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); file_put_contents(__DIR__."/ready", "ready"); while (true) { usleep(1000); }');
        $agent = $this->start($this->request(), directory: $directory);
        $deadline = microtime(true) + 2;
        while (!is_file($directory . '/bin/ready')) {
            if (microtime(true) > $deadline) {
                self::fail('Term-ignoring fixture did not become ready.');
            }
            usleep(1000);
        }
        $now = hrtime(true) / 1e9;
        $agent->cancel($now);
        self::assertNull($agent->takeResult());
        $result = $this->finish($agent, $now + 0.2);
        self::assertSame('cancelled', $result['category']);
        self::assertFalse($result['success']);
    }

    public function testParserRejectsOversizedValidFrameIndependentlyOfPrePollSizeCheck(): void
    {
        $code = <<<'PHP'
$reply = ["action"=>"acquire","sequence"=>1,"success"=>true,"category"=>"acquired","lease"=>["namespace"=>"baander.app:workers","bootId"=>str_repeat("a",32),"epoch"=>1]];
echo str_pad(json_encode($reply), 8193, " ");
file_put_contents(__DIR__."/ready", "ready");
PHP;
        $directory = $this->fixture($code);
        $agent = $this->start($this->request(), directory: $directory);
        $deadline = microtime(true) + 2;
        while (!is_file($directory . '/bin/ready')) {
            if (microtime(true) > $deadline) {
                self::fail('Oversized real-child frame was not written.');
            }
            usleep(1000);
        }
        // Exercise the parser's independent defense with actual child output,
        // bypassing poll's earlier size check deterministically. This reproduces
        // the unsafe late-append branch without a scheduler-dependent race.
        $parser = new \ReflectionMethod(LeaseAgentProcess::class, 'readResult');
        $parsed = $parser->invoke($agent);
        self::assertFalse($parsed['success']);
        self::assertSame('output_limit', $parsed['category']);
        self::assertSame('output_limit', $this->finish($agent)['category']);
    }

    #[DataProvider('invalidReplies')]
    public function testUntrustedOutputCannotGrantAdmission(string $code, string $category): void
    {
        $result = $this->finish($this->start($this->request(), directory: $this->fixture($code)));
        self::assertSame($category, $result['category']);
        self::assertFalse($result['success']);
        self::assertNull($result['lease']);
        self::assertStringNotContainsString('private-fixture-error', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReplies(): iterable
    {
        yield 'malformed' => ['echo "not-json";', 'invalid_response'];
        yield 'extra frame' => ['echo "{}\\n{}";', 'invalid_response'];
        yield 'mismatched sequence' => ['echo json_encode(["action"=>"acquire","sequence"=>2,"success"=>true,"category"=>"acquired","lease"=>["namespace"=>"baander.app:workers","bootId"=>str_repeat("a",32),"epoch"=>1]]);', 'invalid_response'];
        yield 'mismatched identity' => ['echo json_encode(["action"=>"acquire","sequence"=>1,"success"=>true,"category"=>"acquired","lease"=>["namespace"=>"foreign","bootId"=>str_repeat("a",32),"epoch"=>1]]);', 'invalid_response'];
        yield 'oversized stdout' => ['echo str_repeat("x",9000);', 'output_limit'];
        yield 'oversized stderr' => ['fwrite(STDERR,str_repeat("private-fixture-error",500));', 'output_limit'];
    }

    public function testContainmentActionIsRejectedBeforeLaunching(): void
    {
        $request = $this->request();
        $request['action'] = 'acknowledgeContainment';
        $this->expectException(\InvalidArgumentException::class);
        $this->start($request);
    }

    /** @return array{action: string, sequence: int, namespace: string, bootId: string, epoch: ?int, ttlSeconds: int} */
    private function request(string $action = 'acquire', int $sequence = 1, ?int $epoch = null): array
    {
        return ['action' => $action, 'sequence' => $sequence, 'namespace' => 'baander.app:workers', 'bootId' => str_repeat('a', 32), 'epoch' => $epoch, 'ttlSeconds' => 60];
    }

    /** @param array<string, mixed> $request @param array<string, string>|null $environment */
    private function start(array $request, float $timeout = 2, ?string $directory = null, ?array $environment = null): LeaseAgentProcess
    {
        $agent = LeaseAgentProcess::start($request, $directory ?? dirname(__DIR__, 2), hrtime(true) / 1e9, $timeout, $environment ?? $this->environment);
        $this->agents[] = $agent;
        return $agent;
    }

    /** @return array<string, mixed> */
    private function finish(LeaseAgentProcess $agent, float $minimumTime = 0): array
    {
        $deadline = microtime(true) + 3;
        while ($agent->poll(max($minimumTime, hrtime(true) / 1e9))) {
            if (microtime(true) > $deadline) {
                self::fail('Lease agent did not reap within its bounded fixture deadline.');
            }
            usleep(1000);
        }
        $result = $agent->takeResult();
        self::assertNotNull($result);
        return $result;
    }

    private function fixture(string $code): string
    {
        $directory = sys_get_temp_dir() . '/baander-lease-agent-fixture-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        mkdir($directory . '/bin', 0700);
        file_put_contents($directory . '/bin/worker-lease-agent.php', "<?php\n" . $code);
        $this->fixtures[] = $directory;
        return $directory;
    }

    protected function tearDown(): void
    {
        $this->agents = [];
        gc_collect_cycles();
        foreach ($this->fixtures as $directory) {
            foreach (glob($directory . '/bin/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory . '/bin');
            rmdir($directory);
        }
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }
}
