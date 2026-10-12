<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\GrantPathOAuthKernel;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\ZeroGraceOAuthKernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lost-response grace and every other reuse of a refresh token, through the production
 * refresh endpoint on real PostgreSQL and Redis (R1 to R3, R5, R6).
 *
 * A token that was already consumed, presented again with its own DPoP key within the
 * window while its replacement is unused, gets a fresh pair and the replacement is
 * revoked. Every other reuse revokes the chain before the 401; a different key revokes
 * nothing. The two-session cases run a child process (refresh-token-race.php) that waits
 * on a row lock this process holds.
 */
final class RefreshTokenGraceTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'refresh-grace-test-password';

    private static string $sharedDirectory;
    private string $databaseUrl;
    private ProductionOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private Connection $connection;
    private ?Connection $observer = null;
    private User $user;
    private string $ip;
    /** The DPoP key the tokens are issued to; the child processes read it from this file. */
    private SignedDpopProof $key;
    private string $keyPath;

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
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-refresh-grace-' . bin2hex(random_bytes(8));
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
        $this->bootKernel(new GrantPathOAuthKernel(self::$sharedDirectory, $url));
        // Refresh rate limits are per client IP; isolate each test's budget.
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
        // The observer may still hold a row lock a failed test never released; let it go
        // before the cleanup below, which would otherwise wait on it.
        if ($this->observer !== null) {
            while ($this->observer->isTransactionActive()) {
                $this->observer->rollBack();
            }
            $this->observer->close();
        }
        if (isset($this->connection, $this->user)) {
            // Access tokens, with their refresh tokens and token metadata, cascade from the user.
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$this->user->getId()->toString()]);
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
        parent::tearDown();
    }

    /** AE1: the retry of a lost response gets a fresh pair; the unused replacement and its access token are revoked. */
    public function testALostResponseRetryWithinTheWindowGetsAFreshPairAndSupersedesTheReplacement(): void
    {
        $login = $this->login();
        $first = $this->refreshOk($login['refreshToken']);
        $usedAtAfterFirstRotation = $this->refreshRow($login['refreshToken'])['used_at'];
        self::assertIsString($usedAtAfterFirstRotation);

        // The response of the first rotation never reached the client; it retries with the same token.
        $retry = $this->refreshOk($login['refreshToken']);

        self::assertNotSame($first['refreshToken'], $retry['refreshToken']);
        $r1 = $this->refreshRow($login['refreshToken']);
        $r2 = $this->refreshRow($first['refreshToken']);
        $r3 = $this->refreshRow($retry['refreshToken']);
        self::assertSame($r1['id'], $r3['previous_refresh_token_id'], 'The fresh pair descends from the presented token.');
        self::assertSame([false, null], [$r3['revoked'], $r3['used_at']]);
        self::assertSame([true, null], [$r2['revoked'], $r2['used_at']], 'The replacement is revoked, never used.');
        self::assertTrue($this->accessRow($first['accessToken'])['revoked'], 'The replacement access token is revoked with it.');
        self::assertFalse($this->accessRow($retry['accessToken'])['revoked']);
        self::assertSame([false, $usedAtAfterFirstRotation], [$r1['revoked'], $r1['used_at']], 'The presented token stays used, with its first consumption time.');
        self::assertSame(200, $this->me($retry['accessToken'])->getStatusCode(), 'The next API call succeeds.');
        self::assertSame(401, $this->me($first['accessToken'])->getStatusCode());

        // The predecessor rules of the next rotation hold: R3's predecessor R1 is used and not revoked.
        $this->refreshOk($retry['refreshToken']);

        $record = $this->lastLogRecord($r1['chain_id'], 'grace');
        self::assertSame('INFO', $record['level_name']);
        $this->assertNoTokenStringLogged($r1['chain_id'], [$login, $first, $retry]);
    }

    /** AE2: a replacement that was already used makes the presentation a replay; the chain ends before the 401. */
    public function testAReplacementAlreadyUsedRevokesTheChain(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $third = $this->refreshOk($second['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $replay = $this->refresh($login['refreshToken']);

        self::assertSame(401, $replay->getStatusCode(), (string) $replay->getContent());
        $this->assertChainRevoked($chainId);
        self::assertSame(401, $this->refresh($third['refreshToken'])->getStatusCode());
        self::assertSame(401, $this->me($third['accessToken'])->getStatusCode());
        self::assertSame('WARNING', $this->lastLogRecord($chainId, 'replacement used')['level_name']);
        $this->assertNoTokenStringLogged($chainId, [$login, $second, $third]);
    }

    /** The replacement is consumed after the pre-transaction read, while the presentation waits on the row lock. */
    public function testAReplacementConsumedWhileThePresentationWaitsOnTheLockRevokesTheChain(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $this->observer()->beginTransaction();
        $this->observer()->executeQuery('SELECT id FROM oauth_refresh_tokens WHERE token_id = ? FOR UPDATE', [$login['refreshToken']])->free();
        $child = $this->startChild($login['refreshToken']);
        $this->awaitLockWait($child);
        // Another session rotates the replacement before the presentation gets the lock.
        self::assertSame(1, $this->observer()->executeStatement('UPDATE oauth_refresh_tokens SET used_at = NOW() WHERE token_id = ?', [$second['refreshToken']]));
        $this->observer()->commit();

        self::assertSame(401, $this->finish($child)['status']);
        $this->assertChainRevoked($chainId);
        self::assertSame('WARNING', $this->lastLogRecord($chainId, 'replacement used')['level_name']);
    }

    public function testAPresentationOutsideTheWindowRevokesTheChain(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        self::assertSame(1, $this->connection->executeStatement(
            "UPDATE oauth_refresh_tokens SET used_at = NOW() - interval '61 seconds' WHERE token_id = ?",
            [$login['refreshToken']],
        ));

        $late = $this->refresh($login['refreshToken']);

        self::assertSame(401, $late->getStatusCode(), (string) $late->getContent());
        $this->assertChainRevoked($chainId);
        self::assertSame(401, $this->refresh($second['refreshToken'])->getStatusCode());
        self::assertSame('WARNING', $this->lastLogRecord($chainId, 'outside window')['level_name']);
    }

    /** A different key never revokes: the holder of the key keeps the chain. */
    public function testAUsedTokenPresentedWithAnotherKeyRevokesNothing(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $stolen = $this->refresh($login['refreshToken'], new SignedDpopProof());

        self::assertSame(401, $stolen->getStatusCode(), (string) $stolen->getContent());
        self::assertFalse($this->refreshRow($second['refreshToken'])['revoked']);
        self::assertSame(200, $this->me($second['accessToken'])->getStatusCode());
        $this->refreshOk($second['refreshToken']);
        self::assertSame('WARNING', $this->lastLogRecord($chainId, 'different key')['level_name']);
        $this->assertNoTokenStringLogged($chainId, [$login, $second]);
    }

    public function testASupersededTokenPresentedWithItsOwnKeyRevokesTheChain(): void
    {
        $login = $this->login();
        $superseded = $this->refreshOk($login['refreshToken']);
        $graced = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        self::assertTrue($this->refreshRow($superseded['refreshToken'])['revoked']);

        $late = $this->refresh($superseded['refreshToken']);

        self::assertSame(401, $late->getStatusCode(), (string) $late->getContent());
        $this->assertChainRevoked($chainId);
        self::assertSame(401, $this->refresh($graced['refreshToken'])->getStatusCode());
        self::assertSame(401, $this->me($graced['accessToken'])->getStatusCode());
        self::assertSame('WARNING', $this->lastLogRecord($chainId, 'superseded token presented')['level_name']);
    }

    public function testASupersededTokenPresentedWithAnotherKeyRevokesNothing(): void
    {
        $login = $this->login();
        $superseded = $this->refreshOk($login['refreshToken']);
        $graced = $this->refreshOk($login['refreshToken']);

        $stolen = $this->refresh($superseded['refreshToken'], new SignedDpopProof());

        self::assertSame(401, $stolen->getStatusCode(), (string) $stolen->getContent());
        self::assertFalse($this->refreshRow($graced['refreshToken'])['revoked']);
        self::assertSame(200, $this->me($graced['accessToken'])->getStatusCode());
        $this->refreshOk($graced['refreshToken']);
    }

    /** R5: a window of 0 disables the grace; the lost-response case is a replay. */
    public function testAWindowOfZeroAnswersTheLostResponseRetryWith401AndRevokes(): void
    {
        $this->kernel->shutdown();
        $this->bootKernel(new ZeroGraceOAuthKernel(self::$sharedDirectory, $this->databaseUrl));
        self::assertSame(0, $this->kernel->getContainer()->getParameter('auth.refresh.reuse_grace_seconds'));
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $retry = $this->refresh($login['refreshToken']);

        self::assertSame(401, $retry->getStatusCode(), (string) $retry->getContent());
        $this->assertChainRevoked($chainId);
        self::assertSame(401, $this->refresh($second['refreshToken'])->getStatusCode());
        self::assertSame('WARNING', $this->lastLogRecord($chainId, 'outside window')['level_name']);
    }

    /** Two lost responses within the window: each grace supersedes the pair the previous one issued. */
    public function testAGraceRepeatedWithinTheWindowSupersedesThePreviousGrace(): void
    {
        $login = $this->login();
        $first = $this->refreshOk($login['refreshToken']);
        $second = $this->refreshOk($login['refreshToken']);
        $third = $this->refreshOk($login['refreshToken']);

        $r1 = $this->refreshRow($login['refreshToken']);
        self::assertSame([false, true, true, false], [
            $r1['revoked'],
            $this->refreshRow($first['refreshToken'])['revoked'],
            $this->refreshRow($second['refreshToken'])['revoked'],
            $this->refreshRow($third['refreshToken'])['revoked'],
        ]);
        self::assertSame($r1['id'], $this->refreshRow($third['refreshToken'])['previous_refresh_token_id']);
        self::assertSame(1, $this->liveUnusedRefreshTokens($r1['chain_id']));
        self::assertSame(200, $this->me($third['accessToken'])->getStatusCode());
        self::assertSame(401, $this->me($second['accessToken'])->getStatusCode());
        $this->refreshOk($third['refreshToken']);
        self::assertCount(2, $this->logRecords($r1['chain_id'], 'grace'));
    }

    /** Every replacement already revoked: the presentation is refused and the chain stays as it is. */
    public function testAPresentationWhoseReplacementsAreAllRevokedIsRefusedWithTheChainIntact(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        self::assertSame(1, $this->connection->executeStatement('UPDATE oauth_refresh_tokens SET revoked = TRUE WHERE token_id = ?', [$second['refreshToken']]));

        $retry = $this->refresh($login['refreshToken']);

        self::assertSame(401, $retry->getStatusCode(), (string) $retry->getContent());
        $r1 = $this->refreshRow($login['refreshToken']);
        self::assertSame([false, true], [$r1['revoked'], $r1['used_at'] !== null], 'The presented token is neither revoked nor touched.');
        self::assertSame('INFO', $this->lastLogRecord($chainId, 'concurrent loser')['level_name']);
    }

    /**
     * AE3: two database sessions present one token at once. Both wait on the row this process
     * locks; the first to get it consumes the token and issues a pair, the second finds the
     * token consumed, supersedes that pair under the lock and issues its own. One usable pair
     * remains, and the client discards the late answer it did not adopt.
     */
    public function testTwoSimultaneousPresentationsLeaveExactlyOneUsablePair(): void
    {
        $login = $this->login();
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $this->observer()->beginTransaction();
        $this->observer()->executeQuery('SELECT id FROM oauth_refresh_tokens WHERE token_id = ? FOR UPDATE', [$login['refreshToken']])->free();
        $first = $this->startChild($login['refreshToken']);
        $second = $this->startChild($login['refreshToken']);
        $this->awaitLockWait($first);
        $this->awaitLockWait($second);
        $this->observer()->commit();
        $answers = [$this->finish($first), $this->finish($second)];

        self::assertSame([200, 200], [$answers[0]['status'], $answers[1]['status']], json_encode($answers, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->liveUnusedRefreshTokens($chainId), 'Exactly one usable pair remains.');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]));
        $pairs = array_map(static fn (array $answer): array => $answer['body']['data'], $answers);
        $live = array_values(array_filter($pairs, fn (array $pair): bool => !$this->refreshRow($pair['refreshToken'])['revoked']));
        $superseded = array_values(array_filter($pairs, fn (array $pair): bool => $this->refreshRow($pair['refreshToken'])['revoked']));
        self::assertCount(1, $live);
        self::assertCount(1, $superseded);
        self::assertSame($this->refreshRow($login['refreshToken'])['id'], $this->refreshRow($live[0]['refreshToken'])['previous_refresh_token_id']);
        self::assertSame(200, $this->me($live[0]['accessToken'])->getStatusCode());
        self::assertSame(401, $this->me($superseded[0]['accessToken'])->getStatusCode());
        $this->refreshOk($live[0]['refreshToken']);
        self::assertSame(401, $this->refresh($superseded[0]['refreshToken'])->getStatusCode(), 'The superseded token presented later is a replay.');
        self::assertCount(1, $this->logRecords($chainId, 'grace'));
    }

    /** After logout, no token of the chain refreshes (R13 meets the grace: nothing is live to grace into). */
    public function testAfterLogoutAPresentationOfAnEarlierTokenAnswers401(): void
    {
        $login = $this->login();
        $second = $this->refreshOk($login['refreshToken']);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];

        $logout = $this->send('POST', '/api/auth/logout', headers: [
            'Authorization' => 'DPoP ' . $second['accessToken'],
            'DPoP' => $this->key->create('POST', self::ORIGIN . '/api/auth/logout', $second['accessToken']),
        ]);

        self::assertSame(200, $logout->getStatusCode(), (string) $logout->getContent());
        $this->assertChainRevoked($chainId);
        self::assertSame(401, $this->refresh($login['refreshToken'])->getStatusCode());
        self::assertSame(401, $this->refresh($second['refreshToken'])->getStatusCode());
    }

    // --- Rows and logs ---

    /** @return array{id: string, chain_id: string, previous_refresh_token_id: ?string, revoked: bool, used_at: ?string} */
    private function refreshRow(string $refreshToken): array
    {
        $row = $this->connection->fetchAssociative(
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
        $row = $this->connection->fetchAssociative('SELECT revoked FROM oauth_access_tokens WHERE token_id = ?', [self::claims($accessToken)['jti']]);
        self::assertIsArray($row, 'The access token must be persisted.');

        /** @var array{revoked: bool} $row */
        return $row;
    }

    private function liveUnusedRefreshTokens(string $chainId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = ? AND revoked = FALSE AND used_at IS NULL',
            [$chainId],
        );
    }

    private function assertChainRevoked(string $chainId): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]), 'Every refresh token of the chain is revoked.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]), 'Every access token of the chain is revoked.');
    }

    /** @return array<string, mixed> */
    private function lastLogRecord(string $chainId, string $reason): array
    {
        $records = $this->logRecords($chainId, $reason);
        self::assertNotEmpty($records, sprintf('Expected a "%s" record for chain %s.', $reason, $chainId));

        return $records[array_key_last($records)];
    }

    /**
     * The kernel's file handler writes every record as one JSON line.
     *
     * @return list<array<string, mixed>>
     */
    private function logRecords(string $chainId, ?string $reason = null): array
    {
        $path = self::$sharedDirectory . '/logs/prod.log';
        self::assertFileExists($path);
        $records = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            $context = $record['context'] ?? [];
            if (($context['chain_id'] ?? null) !== $chainId) {
                continue;
            }
            if ($reason !== null && ($context['reason'] ?? null) !== $reason) {
                continue;
            }
            $records[] = $record;
        }

        return $records;
    }

    /** @param list<array{accessToken: string, refreshToken: string}> $pairs */
    private function assertNoTokenStringLogged(string $chainId, array $pairs): void
    {
        $serialized = json_encode($this->logRecords($chainId), JSON_THROW_ON_ERROR);
        foreach ($pairs as $pair) {
            self::assertStringNotContainsString($pair['refreshToken'], $serialized, 'A log never carries a refresh token.');
            self::assertStringNotContainsString(self::claims($pair['accessToken'])['jti'], $serialized, 'A log never carries an access token id.');
            self::assertStringNotContainsString($pair['accessToken'], $serialized, 'A log never carries an access token.');
        }
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
    private function startChild(string $refreshToken): array
    {
        $application = 'refresh_grace_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/refresh-token-race.php', self::$sharedDirectory, $application, $this->ip, $this->keyPath, $refreshToken],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
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
                self::fail('The presentation must wait on the refresh token row lock: ' . stream_get_contents($child['output']));
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
                self::fail('The presentation did not finish after the lock was released.');
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

    private function bootKernel(ProductionOAuthKernel $kernel): void
    {
        $this->kernel = $kernel;
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $this->connection = $manager->getConnection();

        if (!isset($this->user)) {
            $users = $container->get('oauth.acceptance.users');
            self::assertInstanceOf(UserRepositoryInterface::class, $users);
            $this->user = User::register(
                new Email('refresh-grace-' . bin2hex(random_bytes(8)) . '@baander.app'),
                password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'Refresh grace user',
            );
            $users->save($this->user);
        }

        // Login issues to the configured first-party SPA client; the row is the one
        // `app:auth:setup-clients` seeds and is left in place.
        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $this->manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'Refresh grace SPA',
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

    private function refresh(string $refreshToken, ?SignedDpopProof $key = null): Response
    {
        $key ??= $this->key;

        return $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $refreshToken], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $this->nonce()),
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
        // The web server clears a pooled EntityManager when a request releases it; this
        // kernel reuses one manager, so clear it the same way between requests.
        if ($this->manager->isOpen()) {
            $this->manager->clear();
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        return json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    }
}
