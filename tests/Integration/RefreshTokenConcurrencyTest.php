<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Service\IssuanceLockTimeout;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use App\Tests\Fixtures\Auth\GrantPathOAuthKernel;
use App\Tests\Fixtures\Auth\IssuanceFaults;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two database sessions, a failing or stalled signer, a lost commit acknowledgment, a
 * stuck lock and fifty rotations, through the production endpoints on real PostgreSQL
 * and Redis (R3, R4, R14, R24; KTD10, KTD11).
 *
 * The production kernel carries two inert fault injectors (IssuanceFaults): a signer
 * that fails once or stalls on a file flag, which holds an issuing transaction open with
 * every lock it took, and a driver that commits and then reports the connection lost. A
 * child process (refresh-race.php) runs the other session; pg_stat_activity shows when it
 * waits on a row lock.
 */
final class RefreshTokenConcurrencyTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'refresh-concurrency-test-password';

    private static string $sharedDirectory;
    private string $databaseUrl;
    private GrantPathOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private Connection $connection;
    private ?Connection $observer = null;
    private User $user;
    private string $ip;
    /** The DPoP key the tokens are issued to; the child processes read it from this file. */
    private SignedDpopProof $key;
    private string $keyPath;
    /** The fault directory of this process; children get one of their own. */
    private string $faultDirectory;
    /** @var list<string> */
    private array $childFaultDirectories = [];
    /** @var list<string> */
    private array $triggers = [];

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$sharedDirectory)) {
            (new Filesystem())->remove(self::$sharedDirectory);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->databaseUrl = $url;
        if (!isset(self::$sharedDirectory)) {
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-refresh-concurrency-' . bin2hex(random_bytes(8));
            self::assertTrue(mkdir(self::$sharedDirectory, 0700));
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            self::assertNotFalse($key);
            self::assertTrue(openssl_pkey_export($key, $privateKey));
            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertSame(strlen($privateKey), file_put_contents(self::$sharedDirectory . '/private.pem', $privateKey));
            self::assertSame(strlen($details['key']), file_put_contents(self::$sharedDirectory . '/public.pem', $details['key']));
            self::assertTrue(chmod(self::$sharedDirectory . '/private.pem', 0600));
            self::assertTrue(chmod(self::$sharedDirectory . '/public.pem', 0600));
        }
        $this->faultDirectory = self::$sharedDirectory . '/faults-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->faultDirectory, 0700));
        $this->bootKernel();
        // Login rate limits are per client IP; isolate each test's budget.
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $dpopKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($dpopKey);
        self::assertTrue(openssl_pkey_export($dpopKey, $dpopPem));
        $this->key = new SignedDpopProof($dpopPem);
        $this->keyPath = self::$sharedDirectory . '/dpop-' . bin2hex(random_bytes(6)) . '.pem';
        self::assertSame(strlen($dpopPem), file_put_contents($this->keyPath, $dpopPem));
        self::assertTrue(chmod($this->keyPath, 0600));
    }

    protected function tearDown(): void
    {
        putenv(IssuanceFaults::ENVIRONMENT_VARIABLE);
        // The observer may still hold a row lock a failed test never released; let it go
        // before the cleanup below, which would otherwise wait on it.
        if ($this->observer !== null) {
            while ($this->observer->isTransactionActive()) {
                $this->observer->rollBack();
            }
            foreach ($this->triggers as $trigger) {
                $this->observer->executeStatement('DROP TRIGGER IF EXISTS ' . $trigger . ' ON oauth_refresh_tokens');
                $this->observer->executeStatement('DROP FUNCTION IF EXISTS ' . $trigger . '()');
            }
        }
        if (isset($this->user)) {
            // Access tokens, with their refresh tokens and token metadata, cascade from the user.
            $this->observer()->executeStatement('DELETE FROM users WHERE id = ?', [$this->user->getId()->toString()]);
        }
        if ($this->observer !== null) {
            $this->observer->close();
        }
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        if (isset($this->keyPath) && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
        $filesystem = new Filesystem();
        if (isset($this->faultDirectory)) {
            $filesystem->remove($this->faultDirectory);
        }
        $filesystem->remove($this->childFaultDirectories);
        parent::tearDown();
    }

    // --- Two sessions ---

    /**
     * R3: session A consumed the token and holds every lock of its issuing transaction
     * (stalled in the signer, after the flush and the metadata); session B presents the same
     * token and waits on the row. A commits its pair; B finds the token consumed, supersedes
     * A's unused pair under the lock and issues its own. One usable pair, chain intact.
     */
    public function testTwoSessionsPresentingOneTokenLeaveExactlyOneUsablePair(): void
    {
        $login = $this->login();
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $stalled = $this->childFaultDirectory();
        touch(IssuanceFaults::path($stalled, IssuanceFaults::SIGN_BLOCK));
        $first = $this->startChild('refresh', $login['refreshToken'], $stalled);
        $this->awaitFlag($first, $stalled, IssuanceFaults::SIGN_BLOCKED);
        $second = $this->startChild('refresh', $login['refreshToken']);
        $this->awaitLockWait($second);
        self::assertTrue(unlink(IssuanceFaults::path($stalled, IssuanceFaults::SIGN_BLOCK)));
        $answers = [$this->finish($first), $this->finish($second)];

        self::assertSame([200, 200], [$answers[0]['status'], $answers[1]['status']], json_encode($answers, JSON_THROW_ON_ERROR));
        $pairs = array_map(static fn (array $answer): array => $answer['body']['data'], $answers);
        self::assertTrue($this->refreshRow($pairs[0]['refreshToken'])['revoked'], 'The pair the first session committed is superseded.');
        self::assertSame([false, null], [$this->refreshRow($pairs[1]['refreshToken'])['revoked'], $this->refreshRow($pairs[1]['refreshToken'])['used_at']]);
        self::assertSame(1, $this->liveUnusedRefreshTokens($chainId), 'Exactly one usable pair remains.');
        self::assertSame(1, $this->liveAccessTokens($chainId));
        self::assertSame(401, $this->me($pairs[0]['accessToken'])->getStatusCode());
        self::assertSame(200, $this->me($pairs[1]['accessToken'])->getStatusCode());
        $this->refreshOk($pairs[1]['refreshToken']);
    }

    /**
     * KTD11: the logout revokes the chain while a refresh of its live token runs in another
     * session. Both sides take refresh rows before access rows, so neither waits on the other
     * in the opposite order: the logout, which queued first, wins; the refresh then finds its
     * token revoked. No deadlock, the chain ends revoked, the refresh never answers 200.
     */
    public function testALogoutConcurrentWithARefreshOfTheChainEndsTheChainWithoutADeadlock(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        $logOffset = $this->logSize();

        // The access row is held, so the logout (refresh rows, then access rows) stops at the
        // access row holding the refresh rows, and the refresh stops at its refresh row.
        $this->observer()->beginTransaction();
        $this->observer()->executeQuery('SELECT id FROM oauth_access_tokens WHERE token_id = ? FOR UPDATE', [self::claims($second['accessToken'])['jti']])->free();
        $logout = $this->startChild('logout', $second['accessToken']);
        $this->awaitLockWait($logout);
        $refresh = $this->startChild('refresh', $second['refreshToken']);
        $this->awaitLockWait($refresh);
        $this->observer()->commit();
        $logoutAnswer = $this->finish($logout);
        $refreshAnswer = $this->finish($refresh);

        self::assertSame(200, $logoutAnswer['status'], json_encode($logoutAnswer, JSON_THROW_ON_ERROR));
        self::assertContains($refreshAnswer['status'], [401, 503], json_encode($refreshAnswer, JSON_THROW_ON_ERROR));
        $this->assertChainRevoked($chainId);
        self::assertStringNotContainsStringIgnoringCase('deadlock', $this->logSince($logOffset), 'Neither side reports a deadlock.');
        self::assertSame(401, $this->refresh($second['refreshToken'])->getStatusCode());
    }

    // --- Failures inside the issuing transaction ---

    /** R4: the signer fails after the consume; the transaction rolls back and the retry succeeds. */
    public function testASigningFailureAfterTheConsumeRollsBackAndTheRetrySucceeds(): void
    {
        $login = $this->login();
        $before = $this->chainRows($this->refreshRow($login['refreshToken'])['chain_id']);
        $this->armFault(IssuanceFaults::SIGN_FAIL_ONCE);

        $failed = $this->refresh($login['refreshToken']);

        $this->assertServerFault($failed);
        $r1 = $this->refreshRow($login['refreshToken']);
        self::assertSame([false, null], [$r1['revoked'], $r1['used_at']], 'The presented token stays unconsumed.');
        self::assertSame($before, $this->chainRows($r1['chain_id']), 'No row of the rolled-back pair remains.');
        self::assertFalse($this->accessRow($login['accessToken'])['revoked']);

        $this->refreshOk($login['refreshToken']);
    }

    /**
     * R4: the flush after the consume fails on the server (the failure that closes the
     * entity manager); the transaction rolls back, the closed manager is released, and the
     * next refresh in the same kernel and coroutine succeeds on a fresh one.
     */
    public function testAFlushFailureAfterTheConsumeRollsBackAndTheSameKernelRefreshesAgain(): void
    {
        $login = $this->login();
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        $before = $this->chainRows($chainId);
        $pools = $this->poolStats();
        $trigger = $this->rejectRefreshTokenInsertsOf($chainId);

        $failed = $this->refresh($login['refreshToken']);

        $this->assertServerFault($failed);
        $r1 = $this->refreshRow($login['refreshToken']);
        self::assertSame([false, null], [$r1['revoked'], $r1['used_at']], 'The presented token stays unconsumed.');
        self::assertSame($before, $this->chainRows($chainId));
        self::assertTrue($this->manager->isOpen(), 'The coroutine holds an open manager again.');

        $this->dropTrigger($trigger);
        $this->refreshOk($login['refreshToken']);
        // A pool may hold one more free instance than before (the manager that replaced the
        // closed one), which is a release; a leak would leave an instance assigned.
        $after = $this->poolStats();
        self::assertSame($pools['redis'], $after['redis'], 'No Redis connection stays checked out.');
        self::assertSame(
            array_column($pools['services'], 'active'),
            array_column($after['services'], 'active'),
            'The closed manager was released, not leaked.',
        );
    }

    /** R14: login signs inside the issuing transaction; a signing failure commits no pair and no metadata. */
    public function testALoginWhoseSigningFailsCommitsNoPairAndNoMetadata(): void
    {
        $login = $this->login();
        $before = $this->userRows();
        $this->armFault(IssuanceFaults::SIGN_FAIL_ONCE);

        $failed = $this->send('POST', '/api/auth/login', json: [
            'email' => $this->user->getEmail(),
            'password' => self::PASSWORD,
        ], headers: ['DPoP' => $this->key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce())]);

        self::assertSame(500, $failed->getStatusCode(), (string) $failed->getContent());
        self::assertSame($before, $this->userRows(), 'No access token, refresh token or metadata row was committed.');
        self::assertSame(200, $this->me($login['accessToken'])->getStatusCode(), 'The earlier session is untouched.');
        $this->login();
    }

    /**
     * The database commits and the driver then reports the connection lost: the pair exists
     * and the client gets a server fault, so its retry with the same token is the lost-response
     * case, which the grace answers with a fresh pair.
     */
    public function testACommitWhoseAcknowledgmentIsLostLeavesAUsablePairForTheRetry(): void
    {
        $login = $this->login();
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        $this->armFault(IssuanceFaults::COMMIT_THROW_ONCE);

        $lost = $this->refresh($login['refreshToken']);

        $this->assertServerFault($lost);
        $r1 = $this->refreshRow($login['refreshToken']);
        self::assertSame([false, true], [$r1['revoked'], $r1['used_at'] !== null], 'The consume was committed.');
        self::assertSame(1, $this->liveUnusedRefreshTokens($chainId), 'The committed pair is usable.');

        $retry = $this->refreshOk($login['refreshToken']);
        self::assertSame($r1['id'], $this->refreshRow($retry['refreshToken'])['previous_refresh_token_id'], 'The retry is graced from the presented token.');
        self::assertSame(1, $this->liveUnusedRefreshTokens($chainId));
        self::assertSame(200, $this->me($retry['accessToken'])->getStatusCode());
    }

    // --- Lock waits and pools ---

    /** KTD11: a lock held past the issuing transaction's lock_timeout is a server fault inside the client's 10 s attempt. */
    public function testALockHeldPastTheLockTimeoutAnswers503WithinTheClientAttemptTimeout(): void
    {
        $login = $this->login();
        $this->observer()->beginTransaction();
        $this->observer()->executeQuery('SELECT id FROM oauth_refresh_tokens WHERE token_id = ? FOR UPDATE', [$login['refreshToken']])->free();

        $started = microtime(true);
        $stuck = $this->refresh($login['refreshToken']);
        $elapsed = microtime(true) - $started;

        $this->assertServerFault($stuck);
        self::assertGreaterThanOrEqual(IssuanceLockTimeout::MILLISECONDS / 1000, $elapsed, 'The transaction waited for the lock before giving up.');
        self::assertLessThan(10.0, $elapsed, 'The fault is answered inside the client attempt timeout.');
        self::assertSame('0', $this->connection->fetchOne('SHOW lock_timeout'), 'The bound is transaction-local and does not leak into later work.');
        $this->observer()->rollBack();

        $r1 = $this->refreshRow($login['refreshToken']);
        self::assertSame([false, null], [$r1['revoked'], $r1['used_at']]);
        $this->refreshOk($login['refreshToken']);
    }

    /** Fifty rotations in one kernel neither grow the entity manager pool nor the Redis pool. */
    public function testFiftyRefreshesLeaveThePoolsAtTheirStartingSizes(): void
    {
        $login = $this->login();
        // The first rotation touches every pooled service the refresh path uses.
        $current = $this->refreshOk($login['refreshToken']);
        $pools = $this->poolStats();
        self::assertNotEmpty($pools['services'], 'The kernel runs its services through pools.');

        for ($rotation = 0; $rotation < 50; $rotation++) {
            $current = $this->refreshOk($current['refreshToken']);
        }

        self::assertSame($pools, $this->poolStats());
        self::assertSame(200, $this->me($current['accessToken'])->getStatusCode());
    }

    // --- Faults ---

    /** Arms a one-shot fault of this process's kernel. */
    private function armFault(string $flag): void
    {
        putenv(IssuanceFaults::ENVIRONMENT_VARIABLE . '=' . $this->faultDirectory);
        touch(IssuanceFaults::path($this->faultDirectory, $flag));
    }

    private function childFaultDirectory(): string
    {
        $directory = self::$sharedDirectory . '/child-faults-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $this->childFaultDirectories[] = $directory;

        return $directory;
    }

    /** A trigger that fails every refresh token INSERT of the chain, so the rotation's flush fails on the server. */
    private function rejectRefreshTokenInsertsOf(string $chainId): string
    {
        $name = 'baander_test_reject_refresh_insert_' . bin2hex(random_bytes(4));
        $this->observer()->executeStatement(sprintf(<<<'SQL'
            CREATE FUNCTION %1$s() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.chain_id = %2$s THEN
                    RAISE EXCEPTION 'injected flush failure';
                END IF;
                RETURN NEW;
            END $$
            SQL, $name, $this->observer()->quote($chainId)));
        $this->observer()->executeStatement(sprintf('CREATE TRIGGER %1$s BEFORE INSERT ON oauth_refresh_tokens FOR EACH ROW EXECUTE FUNCTION %1$s()', $name));
        $this->triggers[] = $name;

        return $name;
    }

    private function dropTrigger(string $name): void
    {
        $this->observer()->executeStatement('DROP TRIGGER ' . $name . ' ON oauth_refresh_tokens');
        $this->observer()->executeStatement('DROP FUNCTION ' . $name . '()');
        $this->triggers = array_values(array_diff($this->triggers, [$name]));
    }

    private function assertServerFault(Response $response): void
    {
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
    }

    // --- Rows, pools and logs ---

    /** @return array{id: string, chain_id: string, previous_refresh_token_id: ?string, revoked: bool, used_at: ?string} */
    private function refreshRow(string $refreshToken): array
    {
        $row = $this->observer()->fetchAssociative(
            'SELECT id, chain_id, previous_refresh_token_id, revoked, used_at FROM oauth_refresh_tokens WHERE token_id = ?',
            [$refreshToken],
        );
        self::assertIsArray($row, 'The refresh token must be persisted.');

        /** @var array{id: string, chain_id: string, previous_refresh_token_id: ?string, revoked: bool, used_at: ?string} $row */
        return $row;
    }

    /** @return array{revoked: bool} */
    private function accessRow(string $accessToken): array
    {
        $row = $this->observer()->fetchAssociative('SELECT revoked FROM oauth_access_tokens WHERE token_id = ?', [self::claims($accessToken)['jti']]);
        self::assertIsArray($row, 'The access token must be persisted.');

        /** @var array{revoked: bool} $row */
        return $row;
    }

    /**
     * Every row of the chain, with its state.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function chainRows(string $chainId): array
    {
        return [
            'refresh' => $this->observer()->fetchAllAssociative('SELECT id, revoked, used_at FROM oauth_refresh_tokens WHERE chain_id = ? ORDER BY id', [$chainId]),
            'access' => $this->observer()->fetchAllAssociative('SELECT id, revoked FROM oauth_access_tokens WHERE chain_id = ? ORDER BY id', [$chainId]),
            'metadata' => $this->observer()->fetchAllAssociative(
                'SELECT m.id FROM oauth_token_metadata m JOIN oauth_access_tokens a ON a.id = m.token_id WHERE a.chain_id = ? ORDER BY m.id',
                [$chainId],
            ),
        ];
    }

    /**
     * The token rows of the test user: the access tokens, their refresh tokens and their metadata.
     *
     * @return array<string, list<mixed>>
     */
    private function userRows(): array
    {
        $userId = $this->user->getId()->toString();

        return [
            'access' => $this->observer()->fetchFirstColumn('SELECT id FROM oauth_access_tokens WHERE user_id = ? ORDER BY id', [$userId]),
            'refresh' => $this->observer()->fetchFirstColumn(
                'SELECT r.id FROM oauth_refresh_tokens r JOIN oauth_access_tokens a ON a.id = r.access_token_id WHERE a.user_id = ? ORDER BY r.id',
                [$userId],
            ),
            'metadata' => $this->observer()->fetchFirstColumn(
                'SELECT m.id FROM oauth_token_metadata m JOIN oauth_access_tokens a ON a.id = m.token_id WHERE a.user_id = ? ORDER BY m.id',
                [$userId],
            ),
        ];
    }

    private function liveUnusedRefreshTokens(string $chainId): int
    {
        return (int) $this->observer()->fetchOne(
            'SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = ? AND revoked = FALSE AND used_at IS NULL',
            [$chainId],
        );
    }

    private function liveAccessTokens(string $chainId): int
    {
        return (int) $this->observer()->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]);
    }

    private function assertChainRevoked(string $chainId): void
    {
        self::assertSame(0, (int) $this->observer()->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]), 'Every refresh token of the chain is revoked.');
        self::assertSame(0, $this->liveAccessTokens($chainId), 'Every access token of the chain is revoked.');
    }

    /**
     * The service pools (entity managers among them) and the Redis connection pool.
     *
     * @return array{services: array<int, array{active: int, free: int, limit: int}>, redis: array{active: int, idle: int, total: int}}
     */
    private function poolStats(): array
    {
        $services = $this->kernel->getContainer()->get('oauth.acceptance.pool_stats');
        self::assertInstanceOf(SwoolePoolStatsProvider::class, $services);
        $redis = $this->kernel->getContainer()->get('oauth.acceptance.redis_pool');
        self::assertInstanceOf(RedisClientFactory::class, $redis);

        return ['services' => $services->getStats(), 'redis' => $redis->getMetrics()];
    }

    private function logSize(): int
    {
        $path = self::$sharedDirectory . '/logs/prod.log';
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /** Everything the kernels of this and the child processes logged since the offset. */
    private function logSince(int $offset): string
    {
        $path = self::$sharedDirectory . '/logs/prod.log';
        self::assertFileExists($path);

        return (string) file_get_contents($path, offset: $offset);
    }

    // --- Second database session ---

    private function observer(): Connection
    {
        if ($this->observer === null) {
            $this->observer = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($this->databaseUrl));
        }

        return $this->observer;
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startChild(string $operation, string $token, ?string $faultDirectory = null): array
    {
        $application = 'refresh_concurrency_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $environment = getenv();
        unset($environment[IssuanceFaults::ENVIRONMENT_VARIABLE]);
        if ($faultDirectory !== null) {
            $environment[IssuanceFaults::ENVIRONMENT_VARIABLE] = $faultDirectory;
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/refresh-race.php', self::$sharedDirectory, $application, $this->ip, $this->keyPath, $operation, $token],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitLockWait(array $child): void
    {
        $deadline = microtime(true) + 20;
        while (true) {
            $this->observer()->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer()->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$child['application']],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The child must wait on a row lock: ' . stream_get_contents($child['output']));
            }
            usleep(5000);
        }
    }

    /**
     * Waits until the child wrote the flag, which the stalled signer does once the issuing transaction holds its locks.
     *
     * @param array{process: resource, output: resource, application: string} $child
     */
    private function awaitFlag(array $child, string $directory, string $flag): void
    {
        $deadline = microtime(true) + 20;
        while (!IssuanceFaults::isSet($directory, $flag)) {
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail(sprintf('The child must reach the %s flag: %s', $flag, stream_get_contents($child['output'])));
            }
            usleep(5000);
        }
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + 30;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                self::fail('The child did not finish.');
            }
            usleep(5000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        self::assertSame(0, proc_close($child['process']), $result);

        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsInt($decoded['status'] ?? null, $result);

        /** @var array{status: int, body: array<string, mixed>} $decoded */
        return $decoded;
    }

    // --- Kernel and requests ---

    private function bootKernel(): void
    {
        $this->kernel = new GrantPathOAuthKernel(self::$sharedDirectory, $this->databaseUrl);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $this->connection = $manager->getConnection();

        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::register(
            new Email('refresh-concurrency-' . bin2hex(random_bytes(8)) . '@baander.app'),
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'Refresh concurrency user',
        );
        $users->save($this->user);

        // Login issues to the configured first-party SPA client; the row is the one
        // `app:auth:setup-clients` seeds and is left in place.
        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $this->manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'Refresh concurrency SPA',
                json_encode([self::ORIGIN . '/callback'], JSON_THROW_ON_ERROR),
                passwordClient: true,
                firstParty: true,
            ));
            $this->manager->flush();
        }
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function login(): array
    {
        $response = $this->send('POST', '/api/auth/login', json: [
            'email' => $this->user->getEmail(),
            'password' => self::PASSWORD,
        ], headers: ['DPoP' => $this->key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce())]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        return ['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken']];
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function refreshOk(string $refreshToken): array
    {
        $response = $this->refresh($refreshToken);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        return ['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken']];
    }

    private function refresh(string $refreshToken): Response
    {
        return $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $refreshToken], headers: [
            'DPoP' => $this->key->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $this->nonce()),
        ]);
    }

    /** Nonces are single-use and endpoint-independent; a nonce-less proof yields a fresh challenge. */
    private function nonce(): string
    {
        $probe = new SignedDpopProof();
        $response = $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => 'nonce-probe-' . bin2hex(random_bytes(8))], headers: [
            'DPoP' => $probe->create('POST', self::ORIGIN . '/api/auth/refresh', 'no-access-token'),
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        return $nonce;
    }

    private function me(string $accessToken): Response
    {
        return $this->send('GET', '/api/auth/me', headers: [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $this->key->create('GET', self::ORIGIN . '/api/auth/me', $accessToken),
        ]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?array $json = null, array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create(
            self::ORIGIN . $path,
            $method,
            server: $server,
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        // The web server releases every pooled service a request took when its coroutine
        // ends (ContextReleasingHttpKernelRequestHandler); this kernel has no coroutine, so
        // release them the same way, which also returns a cleared EntityManager to the pool.
        $this->releasePooledServices();

        return $response;
    }

    private function releasePooledServices(): void
    {
        $pools = $this->kernel->getContainer()->get('oauth.acceptance.service_pools');
        self::assertInstanceOf(ServicePoolContainer::class, $pools);
        $swoole = $this->kernel->getContainer()->get('oauth.acceptance.swoole');
        self::assertInstanceOf(Swoole::class, $swoole);
        // The EntityManager resetter reports each reset through error_log(), which PHPUnit
        // would count as unexpected output of the test.
        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $pools->releaseFromCoroutine($swoole->getCoroutineId());
        } finally {
            ini_set('error_log', $errorLog === false ? '' : $errorLog);
        }
    }

    /** @return array<string, mixed> */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        return json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    }
}
