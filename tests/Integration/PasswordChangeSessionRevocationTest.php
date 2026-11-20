<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\PasswordChangeOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every way of changing a password ends the sessions the old password started, through the
 * production firewall with real PostgreSQL and Redis. Only a user's own change keeps the
 * session that made it.
 */
final class PasswordChangeSessionRevocationTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'session-test-password';

    private static string $sharedDirectory;
    private PasswordChangeOAuthKernel $kernel;
    private Connection $connection;
    private UserRepositoryInterface $users;
    /** @var list<User> */
    private array $created = [];
    private User $user;
    private string $ip;
    private Connection $observer;

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
        if (!isset(self::$sharedDirectory)) {
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-password-sessions-' . bin2hex(random_bytes(8));
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
        $this->kernel = new PasswordChangeOAuthKernel(self::$sharedDirectory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->connection = $manager->getConnection();
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        // Login and reset limits are per client IP; isolate each test's budget.
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->user = $this->createUser(['ROLE_USER']);

        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'Password session SPA',
                json_encode([self::ORIGIN . '/callback'], JSON_THROW_ON_ERROR),
                passwordClient: true,
                firstParty: true,
            ));
            $manager->flush();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $user) {
            $userId = $user->getId()->toString();
            // These production-kernel writes commit independently of DAMA. Durable events
            // have no user foreign key and must leave with the account that produced them.
            $this->connection->executeStatement(
                "DELETE FROM domain_event_outbox WHERE payload->>'user_id' = :userId",
                ['userId' => $userId],
            );
            // Refresh tokens and token metadata cascade from access tokens.
            $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$userId]);
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
        $this->kernel->shutdown();
        parent::tearDown();
    }

    public function testOwnChangeKeepsTheRequestingSessionAndEndsTheOthers(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $this->issueResetToken();

        $response = $this->send('PUT', '/api/auth/me/password', $current['accessToken'], $currentKey, [
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'changed-by-owner-1',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(200, $this->me($current['accessToken'], $currentKey)->getStatusCode());
        $refreshed = $this->refresh($current['refreshToken'], $currentKey);
        self::assertSame(200, $refreshed->getStatusCode(), 'The current session can still refresh.');
        $rotated = json_decode((string) $refreshed->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(200, $this->me($rotated['accessToken'], $currentKey)->getStatusCode(), 'The rotated access token authenticates.');
        self::assertSame(200, $this->refresh($rotated['refreshToken'], $currentKey)->getStatusCode(), 'The rotated chain refreshes again.');
        $this->assertSignedOut($other, $otherKey);
        $this->assertNoResetToken();
    }

    public function testRedeemingAResetTokenEndsEverySession(): void
    {
        [$first, $firstKey] = $this->signIn($this->user);
        [$second, $secondKey] = $this->signIn($this->user);
        $this->issueResetToken('session-reset-token');

        $response = $this->send('POST', '/api/auth/password/reset', json: ['token' => 'session-reset-token', 'password' => 'changed-by-reset-2']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertSignedOut($first, $firstKey);
        $this->assertSignedOut($second, $secondKey);
        $this->signIn($this->user, 'changed-by-reset-2');
    }

    public function testAnAdministratorResetEndsEverySession(): void
    {
        [$session, $key] = $this->signIn($this->user);
        $this->issueResetToken();
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        [$adminSession, $adminKey] = $this->signIn($admin);

        $response = $this->send(
            'POST',
            '/api/admin/users/' . $this->user->getPublicId()->toString() . '/reset-password',
            $adminSession['accessToken'],
            $adminKey,
            ['password' => 'changed-by-admin-3'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertSignedOut($session, $key);
        $this->assertNoResetToken();
        self::assertSame(200, $this->me($adminSession['accessToken'], $adminKey)->getStatusCode(), 'Only the target user is signed out.');
    }

    public function testTheCliResetEndsEverySession(): void
    {
        [$session, $key] = $this->signIn($this->user);
        $this->issueResetToken();

        $command = (new Application($this->kernel))->find('app:user:reset-password');
        $tester = new CommandTester($command);
        $tester->setInputs(['changed-by-cli-4']);
        $tester->execute(['identifier' => $this->user->getEmail()]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $this->assertSignedOut($session, $key);
        $this->assertNoResetToken();
        $this->signIn($this->user, 'changed-by-cli-4');
    }

    /**
     * A session issued before the account's credentials changed stops working even when
     * nothing revoked its tokens: the access token and the refresh chain carry the credential
     * epoch they were issued under, and the account's epoch has moved past it.
     */
    public function testASessionIssuedUnderAnEarlierCredentialEpochIsRefused(): void
    {
        [$session, $key] = $this->signIn($this->user);
        self::assertSame(200, $this->me($session['accessToken'], $key)->getStatusCode());

        $this->connection->executeStatement(
            'UPDATE users SET credential_epoch = credential_epoch + 1 WHERE id = ?',
            [$this->user->getId()->toString()],
        );

        $this->assertSignedOut($session, $key);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ? AND revoked',
            [$this->user->getId()->toString()],
        ), 'Nothing was revoked; the epoch alone refuses the session.');
    }

    /**
     * A login checks the password before a concurrent change commits and reaches issuance
     * while the change still holds the account's row. The login waits for the change and
     * then refuses: no session issued under the old password survives the change.
     */
    public function testALoginWhosePasswordCheckPassedBeforeAChangeCommitsGetsNoSession(): void
    {
        $this->assertReadCommitted();
        $this->connection->beginTransaction();
        try {
            $this->setPassword('changed-during-login-5');
            $login = $this->startLogin(self::PASSWORD);
            $this->awaitWaitOnThisSession($login);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($login);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->liveAccessTokens(), 'The login issued no session.');
        $this->signIn($this->user, 'changed-during-login-5');
    }

    /**
     * A refresh that reaches its transaction while a password change holds the account's row
     * waits for the change, then refuses: the chain the change ended issues nothing.
     */
    public function testARefreshRacingAPasswordChangeLeavesNoUsableToken(): void
    {
        $this->assertReadCommitted();
        [$session, $key, $keyPath] = $this->signInWithExportedKey();

        $this->connection->beginTransaction();
        try {
            $this->setPassword('changed-during-refresh-6');
            $refresh = $this->startRefresh($keyPath, $session['refreshToken']);
            $this->awaitWaitOnThisSession($refresh);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($refresh);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->liveAccessTokens(), 'No access token of the user survives.');
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_refresh_tokens r JOIN oauth_access_tokens a ON a.id = r.access_token_id WHERE a.user_id = ? AND NOT r.revoked',
            [$this->user->getId()->toString()],
        ), 'No refresh token of the user survives.');
        $this->assertSignedOut($session, $key);
    }

    private function assertReadCommitted(): void
    {
        // The fence re-reads the account's epoch in a statement of its own after the lock.
        self::assertSame('read committed', $this->connection->fetchOne('SHOW default_transaction_isolation'));
    }

    /** An operator password reset through the command bus, inside the transaction this test holds open. */
    private function setPassword(string $password): void
    {
        $bus = $this->kernel->getContainer()->get('oauth.acceptance.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new SetUserPasswordCommand($this->user->getEmail(), $password));
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startLogin(string $password): array
    {
        return $this->startChild('totp-login-race.php', [$this->ip, $this->user->getEmail(), $password, '']);
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startRefresh(string $keyPath, string $refreshToken): array
    {
        return $this->startChild('refresh-race.php', [$this->ip, $keyPath, 'refresh', $refreshToken]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{process: resource, output: resource, application: string}
     */
    private function startChild(string $script, array $arguments): array
    {
        $application = 'password_race_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/' . $script, self::$sharedDirectory, $application, ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /**
     * Waits until the child's session waits on a lock (pg_locks shows it ungranted) that this
     * test's open transaction holds. Checked while the child waits, never after a timeout.
     *
     * @param array{process: resource, output: resource, application: string} $child
     */
    private function awaitWaitOnThisSession(array $child): void
    {
        $holder = (int) $this->connection->fetchOne('SELECT pg_backend_pid()');
        $deadline = microtime(true) + 20;
        while (true) {
            $this->observer()->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer()->fetchOne(
                'SELECT 1 FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
                  WHERE a.application_name = ? AND NOT l.granted AND ? = ANY (pg_blocking_pids(a.pid))',
                [$child['application'], $holder],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The child must wait on the lock the password change holds: ' . stream_get_contents($child['output']));
            }
            usleep(5000);
        }
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array{status: int, body: mixed}
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + 30;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                self::fail('The child did not finish after the password change committed.');
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

        /** @var array{status: int, body: mixed} $decoded */
        return $decoded;
    }

    private function observer(): Connection
    {
        if (!isset($this->observer)) {
            $url = getenv('OUTBOX_TEST_DATABASE_URL');
            self::assertIsString($url);
            $this->observer = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        }

        return $this->observer;
    }

    private function liveAccessTokens(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ? AND NOT revoked',
            [$this->user->getId()->toString()],
        );
    }

    /** @return array{array{accessToken: string, refreshToken: string}, SignedDpopProof, string} */
    private function signInWithExportedKey(): array
    {
        $pem = '';
        $generated = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($generated);
        self::assertTrue(openssl_pkey_export($generated, $pem));
        $path = self::$sharedDirectory . '/dpop-' . bin2hex(random_bytes(6)) . '.pem';
        self::assertSame(strlen($pem), file_put_contents($path, $pem));
        self::assertTrue(chmod($path, 0600));
        $key = new SignedDpopProof($pem);
        [$session] = $this->signIn($this->user, key: $key);

        return [$session, $key, $path];
    }

    /** @param list<string> $roles */
    private function createUser(array $roles): User
    {
        $user = User::createByOperator(
            new Email('password-session-' . bin2hex(random_bytes(8)) . '@baander.app'),
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'Password session user',
            $roles,
        );
        $this->users->save($user);
        $this->created[] = $user;

        return $user;
    }

    private function issueResetToken(string $token = 'outstanding-reset-token'): void
    {
        $tokens = $this->kernel->getContainer()->get('oauth.acceptance.password_reset_tokens');
        self::assertInstanceOf(PasswordResetTokenRepositoryInterface::class, $tokens);
        $tokens->issue($this->user->getId(), $token, new \DateTimeImmutable('+1 hour'));
    }

    private function assertNoResetToken(): void
    {
        self::assertFalse((bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM password_reset_tokens WHERE user_id = ?)',
            [$this->user->getId()->toString()],
        ));
    }

    /** @param array{accessToken: string, refreshToken: string} $session */
    private function assertSignedOut(array $session, SignedDpopProof $key): void
    {
        self::assertSame(401, $this->me($session['accessToken'], $key)->getStatusCode(), 'The old access token is rejected.');
        self::assertSame(401, $this->refresh($session['refreshToken'], $key)->getStatusCode(), 'The old refresh token cannot refresh.');
    }

    /** @return array{array{accessToken: string, refreshToken: string}, SignedDpopProof} */
    private function signIn(User $user, string $password = self::PASSWORD, ?SignedDpopProof $key = null): array
    {
        $key ??= new SignedDpopProof();
        $response = $this->send('POST', '/api/auth/login', json: ['email' => $user->getEmail(), 'password' => $password], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()),
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        return [['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken']], $key];
    }

    private function refresh(string $refreshToken, SignedDpopProof $key): Response
    {
        return $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $refreshToken], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $this->nonce()),
        ]);
    }

    private function me(string $accessToken, SignedDpopProof $key): Response
    {
        return $this->send('GET', '/api/auth/me', $accessToken, $key);
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

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?string $accessToken = null, ?SignedDpopProof $key = null, ?array $json = null, array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create(self::ORIGIN . $path, $method, server: $server, content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR));
        if ($accessToken !== null && $key !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $accessToken);
            $request->headers->set('DPoP', $key->create($method, self::ORIGIN . $path, $accessToken));
        }
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
