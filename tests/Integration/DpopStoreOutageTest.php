<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\BorrowLimitingRedisClientFactory;
use App\Tests\Fixtures\Auth\RecordingLiveConnections;
use App\Tests\Fixtures\Auth\RedisOutageOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token endpoints, the DPoP-bound API and the two revocation routes during a Redis
 * outage, on real PostgreSQL and Redis through the production firewall.
 *
 * The refused outage is a kernel whose every Redis client, the DPoP stores and the
 * Symfony cache connections behind the rate limiters and Doctrine's caches, points at a
 * port nothing listens on. The hung outage is the disposable Redis under CLIENT PAUSE,
 * which accepts connections and answers nothing. One chosen Redis call of a request is
 * failed through the counting pool of the working kernel.
 *
 * A 503 during the outage decides nothing: no nonce is issued, no refusal header is
 * sent, and the same token refreshes once the store answers. The logout and the revoke
 * are the exception: they accept a proof whose replay check could not run, so a session
 * still ends during the outage.
 */
final class DpopStoreOutageTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'store-outage-test-password';
    private const string REDIRECT = 'https://baander.app/callback';
    /** Nothing listens here: every connection is refused at once. */
    private const string REFUSED_REDIS = 'redis://127.0.0.1:1';
    /** The web client's per-attempt timeout (ui/web/src/shared/auth/refresh-budget.ts); a 503 must arrive inside it. */
    private const float ATTEMPT_TIMEOUT_SECONDS = 10.0;
    /** Longer than the path to the DPoP read with 1 s read timeouts on every Redis client, so the pause outlasts the request. */
    private const int PAUSE_MILLISECONDS = 15000;

    private static string $sharedDirectory;
    private string $databaseUrl;
    private RedisOutageOAuthKernel $kernel;
    private ?RedisOutageOAuthKernel $refusedKernel = null;
    private EntityManagerInterface $manager;
    private Connection $connection;
    private User $user;
    /** A second account, for the token of another user a revoke may name. */
    private ?User $otherUser = null;
    private string $ip;
    /** Redis borrows of the working kernel's pool during the last handled request. */
    private int $borrowsDuringLastRequest = 0;

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
        $redisUrl = getenv('REDIS_URL');
        if ($url === false || $url === '' || $redisUrl === false || $redisUrl === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->databaseUrl = $url;
        if (!isset(self::$sharedDirectory)) {
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-dpop-store-outage-' . bin2hex(random_bytes(8));
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            self::assertNotFalse($key);
            self::assertTrue(openssl_pkey_export($key, $privateKey));
            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            // Each kernel variant compiles into its own directory and reads the keys from it.
            foreach (['working', 'refused'] as $variant) {
                $directory = self::$sharedDirectory . '/' . $variant;
                self::assertTrue(mkdir($directory, 0700, true));
                self::assertSame(strlen($privateKey), file_put_contents($directory . '/private.pem', $privateKey));
                self::assertSame(strlen($details['key']), file_put_contents($directory . '/public.pem', $details['key']));
                self::assertTrue(chmod($directory . '/private.pem', 0600));
                self::assertTrue(chmod($directory . '/public.pem', 0600));
            }
        }

        $this->kernel = new RedisOutageOAuthKernel(self::$sharedDirectory . '/working', $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $this->connection = $manager->getConnection();
        // Login and refresh rate limits are per client IP; isolate each test's budget.
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::register(
            new Email('store-outage-' . bin2hex(random_bytes(8)) . '@baander.app'),
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'Store outage user',
        );
        $users->save($this->user);

        // Login and passkey login issue to the first-party SPA client; the row is the one
        // `app:auth:setup-clients` seeds and is left in place.
        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $this->manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'Store outage SPA',
                json_encode([self::REDIRECT], JSON_THROW_ON_ERROR),
                passwordClient: true,
                firstParty: true,
            ));
        }
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->user)) {
            // Tokens, token metadata and passkeys cascade from the user.
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$this->user->getId()->toString()]);
        }
        if (isset($this->connection) && $this->otherUser !== null) {
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$this->otherUser->getId()->toString()]);
        }
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        $this->refusedKernel?->shutdown();
        $this->refusedKernel = null;
        parent::tearDown();
    }

    /**
     * A refused store answers 503 with Retry-After and no nonce, at once (the rate
     * limiters before the DPoP check neither throw nor block); the same token refreshes
     * once the store answers, with no sign-out in between.
     */
    public function testARefreshDuringARefusedOutageAnswers503AndTheSameTokenRefreshesOnceTheStoreAnswers(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $refused = $this->refusedKernel();

        $started = microtime(true);
        $response = $this->refresh($login['refreshToken'], $key, $login['nonce'], $refused);
        $elapsed = microtime(true) - $started;

        self::assertStoreUnavailable($response);
        self::assertLessThan(5.0, $elapsed, sprintf('A refused connection is answered without waiting out a read timeout, not after %.1f s.', $elapsed));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE token_id = ? AND used_at IS NULL AND revoked = FALSE', [$login['refreshToken']]), 'The token was neither consumed nor revoked.');

        // The nonce from the login was never read by the refused kernel, so it is still good.
        $retry = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getContent());
    }

    public function testLoginDuringTheOutageAnswers503WithoutANonce(): void
    {
        $uri = self::ORIGIN . '/api/auth/login';
        $key = new SignedDpopProof();

        // The nonce check fails before any token is issued.
        $withNonce = $this->loginRequest($key->createWithNonce('POST', $uri, $this->nonce()), $this->refusedKernel());
        self::assertStoreUnavailable($withNonce);

        // No challenge is issued either: a nonce that was not stored would only be challenged again.
        $withoutNonce = $this->loginRequest($key->create('POST', $uri, 'no-access-token'), $this->refusedKernel());
        self::assertStoreUnavailable($withoutNonce);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]));
    }

    public function testPasskeyLoginDuringTheOutageAnswers503WithoutSpendingTheAssertion(): void
    {
        $uri = self::ORIGIN . '/api/auth/login/passkey';
        $key = new SignedDpopProof();
        $assertion = $this->passkeyAssertion($this->registerPasskey());

        $response = $this->passkeyLogin($assertion, $key->createWithNonce('POST', $uri, $this->nonce()), $this->refusedKernel());

        self::assertStoreUnavailable($response);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]));

        // The proof is checked before the ceremony, so the assertion is still redeemable once the store answers.
        $recovered = $this->passkeyLogin($assertion, $key->createWithNonce('POST', $uri, $this->nonce()));
        self::assertSame(200, $recovered->getStatusCode(), (string) $recovered->getContent());
        self::assertIsString($recovered->headers->get('DPoP-Nonce'));
    }

    public function testTheTokenEndpointDuringTheOutageAnswers503WithRetryAfter(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        $response = $this->tokenRequest($key, $login['nonce'], [
            'grant_type' => 'refresh_token',
            'client_id' => $this->spaClientId(),
            'refresh_token' => $login['refreshToken'],
        ], $this->refusedKernel());

        self::assertStoreUnavailable($response);
    }

    /** A database fault during the grant is answered like the first-party refresh answers it. */
    public function testTheTokenEndpointAnswersADatabaseFaultWith503AndRetryAfter(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $form = [
            'grant_type' => 'refresh_token',
            'client_id' => $this->spaClientId(),
            'refresh_token' => $login['refreshToken'],
        ];

        // While PostgreSQL restarts it ends every session and refuses new ones.
        $database = $this->connection->getDatabase();
        self::assertIsString($database);
        $admin = DriverManager::getConnection(['dbname' => 'postgres'] + $this->connection->getParams());
        try {
            $admin->executeStatement(sprintf('ALTER DATABASE %s WITH ALLOW_CONNECTIONS false', $admin->quoteSingleIdentifier($database)));
            $admin->executeQuery('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?', [$database]);

            $response = $this->tokenRequest($key, $login['nonce'], $form);
        } finally {
            $admin->executeStatement(sprintf('ALTER DATABASE %s WITH ALLOW_CONNECTIONS true', $admin->quoteSingleIdentifier($database)));
            $admin->close();
            $this->connection->close();
        }

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertSame(['message' => 'An unexpected error occurred.', 'code' => 503], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', (string) $response->getContent());

        // The grant decided nothing: the token still rotates once the database is back.
        $retry = $this->refresh($login['refreshToken'], $key, $this->nonce());
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getContent());
    }

    public function testADpopBoundRequestDuringTheOutageAnswers503NotForbiddenWhileAReplayIsStillForbidden(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        $outage = $this->me($login['accessToken'], $key, $this->refusedKernel());
        self::assertStoreUnavailable($outage);

        $proof = $key->create('GET', self::ORIGIN . '/api/auth/me', $login['accessToken']);
        $first = $this->send('GET', '/api/auth/me', headers: ['Authorization' => 'DPoP ' . $login['accessToken'], 'DPoP' => $proof]);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $replay = $this->send('GET', '/api/auth/me', headers: ['Authorization' => 'DPoP ' . $login['accessToken'], 'DPoP' => $proof]);
        self::assertSame(403, $replay->getStatusCode(), (string) $replay->getContent());
    }

    /**
     * A Redis that accepts connections but does not answer yields the 503 within the
     * client's attempt timeout. The measured path includes the rate limiters and the
     * Doctrine cache reads that precede the DPoP check, each bounded by its read timeout.
     */
    public function testAHungStoreAnswers503WithinTheClientsAttemptTimeoutAndTheSameKernelRefreshesAfterwards(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        // The pause stops every client, new connections included, so the unpause goes over
        // this connection and returns once the pause has ended; its read timeout outlasts it.
        $redis = $this->redis(readTimeoutSeconds: self::PAUSE_MILLISECONDS / 1000 + 5.0);
        self::assertTrue($redis->rawCommand('CLIENT', 'PAUSE', (string) self::PAUSE_MILLISECONDS, 'ALL'));
        $started = microtime(true);
        try {
            $response = $this->refresh($login['refreshToken'], $key, $login['nonce']);
            $elapsed = microtime(true) - $started;
        } finally {
            self::assertTrue($redis->rawCommand('CLIENT', 'UNPAUSE'));
            $redis->close();
        }

        self::assertStoreUnavailable($response);
        self::assertLessThan(
            self::ATTEMPT_TIMEOUT_SECONDS,
            $elapsed,
            sprintf('The 503 took %.1f s; the client gives up on the attempt after %.0f s.', $elapsed, self::ATTEMPT_TIMEOUT_SECONDS),
        );

        // The same kernel, the same token and the unread login nonce: the outage left nothing behind.
        $retry = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getContent());
    }

    /** The tokens are committed before the next nonce is stored, so that store failing costs only the header. */
    public function testARefreshWhosePostCommitNonceStoreFailsAnswers200WithoutANonceAndTheNextRefreshGetsOneChallenge(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $pool = $this->pool();

        // Count the Redis calls of a refresh; the last one is the post-commit nonce store.
        $pool->resetBorrows();
        $first = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $calls = $this->borrowsDuringLastRequest;
        self::assertGreaterThanOrEqual(3, $calls, 'A refresh reads the nonce, records the jti and stores the next nonce.');
        $firstData = json_decode((string) $first->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $firstNonce = $first->headers->get('DPoP-Nonce');
        self::assertIsString($firstNonce);

        $pool->resetBorrows();
        $pool->failFrom($calls);
        try {
            $second = $this->refresh($firstData['refreshToken'], $key, $firstNonce);
        } finally {
            $pool->failFrom(null);
        }
        self::assertSame(200, $second->getStatusCode(), (string) $second->getContent());
        self::assertFalse($second->headers->has('DPoP-Nonce'));
        $secondData = json_decode((string) $second->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertNotSame($firstData['refreshToken'], $secondData['refreshToken']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE token_id = ? AND used_at IS NOT NULL', [$firstData['refreshToken']]), 'The presented token was consumed.');

        // Without a nonce the next refresh gets one challenge, then succeeds with the challenged nonce.
        $challenge = $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $secondData['refreshToken']], headers: [
            'DPoP' => $key->create('POST', self::ORIGIN . '/api/auth/refresh', 'no-access-token'),
        ]);
        self::assertSame(400, $challenge->getStatusCode(), (string) $challenge->getContent());
        self::assertSame('use_dpop_nonce', json_decode((string) $challenge->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);
        $third = $this->refresh($secondData['refreshToken'], $key, $nonce);
        self::assertSame(200, $third->getStatusCode(), (string) $third->getContent());
    }

    public function testASecondRefreshOnTheSameKernelAfterTheOutageWorks(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $pool = $this->pool();

        $pool->resetBorrows();
        $pool->failFrom(1);
        try {
            $outage = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        } finally {
            $pool->failFrom(null);
        }
        self::assertStoreUnavailable($outage);

        $retry = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getContent());
        self::assertIsString($retry->headers->get('DPoP-Nonce'));
    }

    // --- Logout and revoke during the outage ---

    /**
     * A logout during the refused outage ends the session: the proof is accepted without
     * the replay check, the chain is revoked, the sockets opened with it are closed, and
     * one warning names the route, the key thumbprint and the user. Any other route keeps
     * the 503, and once Redis is back the pair refuses to refresh.
     */
    public function testALogoutDuringTheRefusedOutageAnswers200AndRevokesTheChain(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        $refused = $this->refusedKernel();

        self::assertStoreUnavailable($this->me($login['accessToken'], $key, $refused));

        $closeOffset = RecordingLiveConnections::size(self::$sharedDirectory . '/refused');
        $proof = $key->create('POST', self::ORIGIN . '/api/auth/logout', $login['accessToken']);
        $logout = $this->logout($login['accessToken'], $proof, $refused);

        self::assertSame(200, $logout->getStatusCode(), (string) $logout->getContent());
        $this->assertChainRevoked($chainId);
        self::assertSame(
            [$this->chainAccessTokenIds($chainId)],
            RecordingLiveConnections::closedAccessTokens(self::$sharedDirectory . '/refused', (int) getmypid(), $closeOffset),
            'The logout asked the web server to close the sockets of every access token of the chain, once.',
        );
        $records = $this->acceptedWithoutReplayCheck('refused', 'auth_logout');
        self::assertCount(1, $records, 'One warning per accepted proof.');
        self::assertSame('WARNING', $records[0]['level_name']);
        self::assertSame($key->thumbprint(), $records[0]['context']['jkt'] ?? null);
        $serialized = json_encode($records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($login['accessToken'], $serialized, 'The warning never carries the access token.');
        self::assertStringNotContainsString(self::claims($login['accessToken'])['jti'], $serialized, 'The warning never carries the access token id.');
        self::assertStringNotContainsString($login['refreshToken'], $serialized, 'The warning never carries the refresh token.');
        self::assertStringNotContainsString($proof, $serialized, 'The warning never carries the proof.');

        $refresh = $this->refresh($login['refreshToken'], $key, $this->nonce());
        self::assertSame(401, $refresh->getStatusCode(), (string) $refresh->getContent());
    }

    /**
     * Through the counting pool of the working kernel with every borrow failing: the jti
     * check is the only Redis call a logout makes, so no nonce is read or stored, and the
     * logout still ends the session.
     */
    public function testALogoutWhoseEveryPoolBorrowFailsTouchesNoNonceStoreAndStillRevokes(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $chainId = $this->refreshRow($login['refreshToken'])['chain_id'];
        $pool = $this->pool();

        $pool->resetBorrows();
        $pool->failFrom(1);
        try {
            $logout = $this->logout($login['accessToken'], $key->create('POST', self::ORIGIN . '/api/auth/logout', $login['accessToken']));
        } finally {
            $pool->failFrom(null);
        }

        self::assertSame(200, $logout->getStatusCode(), (string) $logout->getContent());
        self::assertSame(1, $this->borrowsDuringLastRequest, 'The replay check is the only Redis call of a logout: the nonce store is never touched.');
        $this->assertChainRevoked($chainId);
        self::assertCount(1, $this->acceptedWithoutReplayCheck('working', 'auth_logout'));
    }

    /**
     * A captured logout proof whose logout succeeded meets a revoked access token, during
     * the outage and after it: the authenticator answers 401 before any proof is read. A
     * copy of a logout that never arrived is the logout, inside its acceptance window. A
     * copy for another token or from another key is refused on the degraded path.
     */
    public function testALogoutProofReplayedDuringTheOutageEndsNoOtherSession(): void
    {
        $key = new SignedDpopProof();
        $uri = self::ORIGIN . '/api/auth/logout';
        $refused = $this->refusedKernel();

        $first = $this->login($key);
        $proof = $key->create('POST', $uri, $first['accessToken']);
        self::assertSame(200, $this->logout($first['accessToken'], $proof, $refused)->getStatusCode());
        self::assertSame(401, $this->logout($first['accessToken'], $proof, $refused)->getStatusCode(), 'The replay meets a revoked token during the outage.');
        self::assertSame(401, $this->logout($first['accessToken'], $proof)->getStatusCode(), 'The replay meets a revoked token once Redis is back; its jti was never recorded, so this is no 403.');

        $lost = $this->login($key);
        $lostChainId = $this->refreshRow($lost['refreshToken'])['chain_id'];
        $lostProof = $key->create('POST', $uri, $lost['accessToken']);
        self::assertSame(200, $this->logout($lost['accessToken'], $lostProof, $refused)->getStatusCode(), 'The late copy of a lost logout is the logout.');
        $this->assertChainRevoked($lostChainId);

        $third = $this->login($key);
        $thirdChainId = $this->refreshRow($third['refreshToken'])['chain_id'];
        $otherAth = $key->create('POST', $uri, $lost['accessToken']);
        self::assertSame(403, $this->logout($third['accessToken'], $otherAth, $refused)->getStatusCode(), 'A proof hashing another token is refused during the outage.');
        $otherKey = new SignedDpopProof();
        self::assertSame(403, $this->logout($third['accessToken'], $otherKey->create('POST', $uri, $third['accessToken']), $refused)->getStatusCode(), 'A proof from another key is refused during the outage.');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = ? AND revoked = FALSE', [$thirdChainId]), 'The refused copies ended nothing.');
    }

    /**
     * A revoke during the refused outage ends at most a chain of the caller: a foreign
     * token revokes nothing, the caller's own token ends the session. The proof does not
     * cover the body, so a captured revoke replayed with the same user's other chain id
     * revokes that chain; this is the residual the acceptance window bounds. The proof's
     * jti is never recorded, so the same proof is answered the same once Redis is back,
     * and only then does the working store make its next copy a replay.
     */
    public function testARevokeDuringTheRefusedOutageEndsOnlyAChainOfTheCaller(): void
    {
        $key = new SignedDpopProof();
        $uri = self::ORIGIN . '/api/oauth/revoke';
        $refused = $this->refusedKernel();
        $caller = $this->login($key);
        $callerChainId = $this->refreshRow($caller['refreshToken'])['chain_id'];
        $other = $this->login($key);
        $otherChainId = $this->refreshRow($other['refreshToken'])['chain_id'];
        $foreign = $this->login(new SignedDpopProof(), $this->otherUser());

        $captured = $key->create('POST', $uri, $caller['accessToken']);
        self::assertSame(200, $this->revoke($caller['accessToken'], $captured, $foreign['refreshToken'], $refused)->getStatusCode());
        self::assertFalse($this->refreshRow($foreign['refreshToken'])['revoked'], 'A foreign token revokes nothing.');
        self::assertCount(1, $this->acceptedWithoutReplayCheck('refused', 'oauth_revoke'));

        self::assertSame(200, $this->revoke($caller['accessToken'], $captured, $other['refreshToken'], $refused)->getStatusCode());
        $this->assertChainRevoked($otherChainId);
        self::assertCount(2, $this->acceptedWithoutReplayCheck('refused', 'oauth_revoke'), 'One warning per accepted proof.');

        self::assertSame(200, $this->revoke($caller['accessToken'], $captured, $foreign['refreshToken'])->getStatusCode(), 'The jti was never recorded: the working store sees the proof for the first time.');
        self::assertSame(403, $this->revoke($caller['accessToken'], $captured, $foreign['refreshToken'])->getStatusCode(), 'The working store recorded it now.');
        self::assertFalse($this->refreshRow($foreign['refreshToken'])['revoked']);

        $own = $key->create('POST', $uri, $caller['accessToken']);
        self::assertSame(200, $this->revoke($caller['accessToken'], $own, $caller['refreshToken'], $refused)->getStatusCode());
        $this->assertChainRevoked($callerChainId);
        self::assertSame(401, $this->refresh($caller['refreshToken'], $key, $this->nonce())->getStatusCode());
    }

    private function logout(string $accessToken, string $proof, ?RedisOutageOAuthKernel $kernel = null): Response
    {
        return $this->send('POST', '/api/auth/logout', headers: [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $proof,
        ], kernel: $kernel);
    }

    private function revoke(string $accessToken, string $proof, string $token, ?RedisOutageOAuthKernel $kernel = null): Response
    {
        return $this->send('POST', '/api/oauth/revoke', json: ['token' => $token, 'tokenTypeHint' => 'refresh_token'], headers: [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $proof,
        ], kernel: $kernel);
    }

    private function otherUser(): User
    {
        if ($this->otherUser === null) {
            $users = $this->kernel->getContainer()->get('oauth.acceptance.users');
            self::assertInstanceOf(UserRepositoryInterface::class, $users);
            $this->otherUser = User::register(
                new Email('store-outage-other-' . bin2hex(random_bytes(8)) . '@baander.app'),
                password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'Store outage other user',
            );
            $users->save($this->otherUser);
        }

        return $this->otherUser;
    }

    /** @return array{id: string, chain_id: string, revoked: bool} */
    private function refreshRow(string $refreshToken): array
    {
        $row = $this->connection->fetchAssociative('SELECT id, chain_id, revoked FROM oauth_refresh_tokens WHERE token_id = ?', [$refreshToken]);
        self::assertIsArray($row, 'The refresh token must be persisted.');

        /** @var array{id: string, chain_id: string, revoked: bool} $row */
        return $row;
    }

    /**
     * The token ids (the JWT jti) of every access token of the chain, in the order a revocation lists them.
     *
     * @return list<string>
     */
    private function chainAccessTokenIds(string $chainId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT token_id FROM oauth_access_tokens WHERE chain_id = ? ORDER BY id', [$chainId]);

        return $ids;
    }

    private function assertChainRevoked(string $chainId): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]), 'Every refresh token of the chain is revoked.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE chain_id = ? AND revoked = FALSE', [$chainId]), 'Every access token of the chain is revoked.');
    }

    /** @return array<string, mixed> */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        return $claims;
    }

    /**
     * The warnings the given kernel variant logged for a proof it accepted without the
     * replay check on the route, for this test's user; the file handler writes one JSON
     * line per record, and the refused kernel's file is shared by every test of the class.
     *
     * @return list<array<string, mixed>>
     */
    private function acceptedWithoutReplayCheck(string $variant, string $route): array
    {
        $path = self::$sharedDirectory . '/' . $variant . '/logs/prod.log';
        self::assertFileExists($path);
        $records = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            $context = $record['context'] ?? [];
            if (($context['route'] ?? null) !== $route || ($context['user_id'] ?? null) !== $this->user->getId()->toString()) {
                continue;
            }
            $records[] = $record;
        }

        return $records;
    }

    private static function assertStoreUnavailable(Response $response): void
    {
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(503, $body['error']['code'] ?? null, (string) $response->getContent());
        self::assertArrayNotHasKey('data', $body);
    }

    private function refusedKernel(): RedisOutageOAuthKernel
    {
        if ($this->refusedKernel === null) {
            $this->refusedKernel = new RedisOutageOAuthKernel(self::$sharedDirectory . '/refused', $this->databaseUrl, self::REFUSED_REDIS);
            $this->refusedKernel->boot();
        }

        return $this->refusedKernel;
    }

    private function pool(): BorrowLimitingRedisClientFactory
    {
        $pool = $this->kernel->getContainer()->get(RedisOutageOAuthKernel::REDIS);
        self::assertInstanceOf(BorrowLimitingRedisClientFactory::class, $pool);

        return $pool;
    }

    /** A connection of the test's own to the disposable Redis, outside every pool of the kernels. */
    private function redis(float $readTimeoutSeconds): Redis
    {
        $dsn = (string) getenv('REDIS_URL');
        self::assertSame(1, preg_match('#^redis://(?:[^:@]+:(?<pass>[^@]+)@)?(?<host>[^:]+):(?<port>\d+)$#', $dsn, $parts), 'REDIS_URL is a plain redis:// DSN.');
        $redis = new Redis();
        self::assertTrue($redis->connect($parts['host'], (int) $parts['port'], 2.0, null, 0, $readTimeoutSeconds));
        if ($parts['pass'] !== '') {
            self::assertTrue($redis->auth($parts['pass']));
        }

        return $redis;
    }

    private function spaClientId(): string
    {
        $spaClientId = $this->kernel->getContainer()->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);

        return $spaClientId;
    }

    /** @return array{accessToken: string, refreshToken: string, nonce: string} */
    private function login(SignedDpopProof $key, ?User $user = null): array
    {
        $response = $this->loginRequest($key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()), user: $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        return ['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken'], 'nonce' => $nonce];
    }

    private function loginRequest(string $proof, ?RedisOutageOAuthKernel $kernel = null, ?User $user = null): Response
    {
        return $this->send('POST', '/api/auth/login', json: [
            'email' => ($user ?? $this->user)->getEmail(),
            'password' => self::PASSWORD,
        ], headers: ['DPoP' => $proof], kernel: $kernel);
    }

    private function refresh(string $refreshToken, SignedDpopProof $key, string $nonce, ?RedisOutageOAuthKernel $kernel = null): Response
    {
        return $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $refreshToken], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $nonce),
        ], kernel: $kernel);
    }

    /** Nonces are single-use and endpoint-independent; a nonce-less proof yields a fresh challenge from the working kernel. */
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

    private function me(string $accessToken, SignedDpopProof $key, ?RedisOutageOAuthKernel $kernel = null): Response
    {
        return $this->send('GET', '/api/auth/me', headers: [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $key->create('GET', self::ORIGIN . '/api/auth/me', $accessToken),
        ], kernel: $kernel);
    }

    /** @param array<string, string> $form */
    private function tokenRequest(SignedDpopProof $key, string $nonce, array $form, ?RedisOutageOAuthKernel $kernel = null): Response
    {
        return $this->send('POST', '/api/oauth/token', form: $form, headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/oauth/token', $nonce),
        ], kernel: $kernel);
    }

    private function registerPasskey(): WebAuthnTestCredential
    {
        $credential = new WebAuthnTestCredential($this->user->getId()->toString(), RedisOutageOAuthKernel::RELYING_PARTY);
        $passkeys = $this->kernel->getContainer()->get('oauth.acceptance.passkeys');
        self::assertInstanceOf(PasskeyRepositoryInterface::class, $passkeys);
        $passkeys->save($credential->passkey(), $this->user->getId());

        return $credential;
    }

    /** @return array{challengeKey: string, response: array<string, mixed>} */
    private function passkeyAssertion(WebAuthnTestCredential $credential): array
    {
        $options = $this->send('POST', '/api/auth/passkey/authenticate/options', json: ['userId' => $this->user->getId()->toString()]);
        self::assertSame(200, $options->getStatusCode(), (string) $options->getContent());
        $data = json_decode((string) $options->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertIsString($data['challengeKey']);
        self::assertIsString($data['options']['challenge']);

        return ['challengeKey' => $data['challengeKey'], 'response' => $credential->assert($data['options']['challenge'], self::ORIGIN)];
    }

    /** @param array{challengeKey: string, response: array<string, mixed>} $assertion */
    private function passkeyLogin(array $assertion, string $proof, ?RedisOutageOAuthKernel $kernel = null): Response
    {
        return $this->send('POST', '/api/auth/login/passkey', json: $assertion, headers: ['DPoP' => $proof], kernel: $kernel);
    }

    /**
     * Handles the request on the given kernel, the working one by default, and records
     * the working pool's borrows made while handling it (not while terminating).
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string> $form
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?array $json = null, array $form = [], array $headers = [], ?RedisOutageOAuthKernel $kernel = null): Response
    {
        $kernel ??= $this->kernel;
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create(
            self::ORIGIN . $path,
            $method,
            $form,
            server: $server,
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $pool = $kernel === $this->kernel ? $this->pool() : null;
        $before = $pool?->borrows() ?? 0;
        $response = $kernel->handle($request);
        if ($pool !== null) {
            $this->borrowsDuringLastRequest = $pool->borrows() - $before;
        }
        $kernel->terminate($request, $response);
        // The web server clears a pooled EntityManager when a request releases it; each
        // kernel here reuses one manager, so clear it the same way between requests, or a
        // row another kernel revoked is still read from this one's identity map.
        $manager = $kernel->getContainer()->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        if ($manager->isOpen()) {
            $manager->clear();
        }

        return $response;
    }
}
