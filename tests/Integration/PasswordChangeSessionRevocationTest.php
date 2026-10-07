<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\PasswordChangeOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
            // Refresh tokens and token metadata cascade from access tokens.
            $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$user->getId()->toString()]);
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()->toString()]);
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
        self::assertSame(200, $this->refresh($current['refreshToken'], $currentKey)->getStatusCode(), 'The current session can still refresh.');
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
            '/api/admin/users/' . $this->user->getId()->toString() . '/reset-password',
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
    private function signIn(User $user, string $password = self::PASSWORD): array
    {
        $key = new SignedDpopProof();
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
