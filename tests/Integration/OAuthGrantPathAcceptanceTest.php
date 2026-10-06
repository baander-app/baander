<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\GrantPathOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token-minting entry points through the production firewall, with real PostgreSQL and Redis.
 *
 * Password login, passkey login, and refresh are the only first-party ways to mint tokens.
 * Passkey login signs a real WebAuthn assertion with an ES256 test authenticator.
 */
final class OAuthGrantPathAcceptanceTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'grant-path-test-password';
    private const string REDIRECT = 'https://baander.app/callback';

    private static string $sharedDirectory;
    private GrantPathOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private Connection $connection;
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
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-oauth-grant-paths-' . bin2hex(random_bytes(8));
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
        $this->kernel = new GrantPathOAuthKernel(self::$sharedDirectory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $this->connection = $manager->getConnection();
        // Login rate limits are per client IP; isolate each test's budget.
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::register(
            new Email('grant-path-' . bin2hex(random_bytes(8)) . '@baander.app'),
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'Grant path user',
        );
        $users->save($this->user);

        // Login and passkey login always issue to the configured first-party SPA client.
        // The row is the same one `app:auth:setup-clients` seeds and is left in place.
        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $this->manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'Grant path SPA',
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
            // Refresh tokens and token metadata cascade from access tokens; passkeys cascade from the user.
            $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]);
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$this->user->getId()->toString()]);
        }
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    public function testPasswordLoginPersistsTheProofKeyBindingOfTheIssuedAccessToken(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        $claims = self::claims($login['accessToken']);
        self::assertSame(['jkt' => $key->thumbprint()], $claims['cnf'] ?? null);
        self::assertSame($key->thumbprint(), $this->storedBinding($claims['jti']));
    }

    public function testRefreshRejectsAProofSignedByAnotherKey(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        $stolen = $this->refresh($login['refreshToken'], new SignedDpopProof(), $login['nonce']);
        self::assertSame(401, $stolen->getStatusCode(), (string) $stolen->getContent());

        // The rejected attempt neither consumed the token nor revoked its chain.
        $owner = $this->refresh($login['refreshToken'], $key, $this->nonce());
        self::assertSame(200, $owner->getStatusCode(), (string) $owner->getContent());
    }

    public function testRefreshRotatesWithinTheBindingAndRevokesTheChainOnReplay(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        $rotated = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(200, $rotated->getStatusCode(), (string) $rotated->getContent());
        $data = json_decode((string) $rotated->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertNotSame($login['refreshToken'], $data['refreshToken']);
        $claims = self::claims($data['accessToken']);
        self::assertSame(['jkt' => $key->thumbprint()], $claims['cnf'] ?? null);
        self::assertSame($key->thumbprint(), $this->storedBinding($claims['jti']));

        $replay = $this->refresh($login['refreshToken'], $key, $this->nonce());
        self::assertSame(401, $replay->getStatusCode(), (string) $replay->getContent());
        $successor = $this->refresh($data['refreshToken'], $key, $this->nonce());
        self::assertSame(401, $successor->getStatusCode(), (string) $successor->getContent());
    }

    public function testRefreshRejectsARevokedRefreshToken(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        self::assertSame(1, $this->connection->executeStatement(
            'UPDATE oauth_refresh_tokens SET revoked = TRUE WHERE token_id = ?',
            [$login['refreshToken']],
        ));

        $response = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testPasskeyLoginIssuesProofBoundTokensUsableOnTheApiAndRefreshableWithTheSameKey(): void
    {
        $key = new SignedDpopProof();
        $credential = $this->registerPasskey();
        $assertion = $this->passkeyAssertion($credential);

        // The proof is checked before the ceremony, so the nonce challenge leaves the assertion redeemable.
        $challenge = $this->passkeyLogin($assertion, $key->create('POST', self::ORIGIN . '/api/auth/login/passkey', 'no-access-token'));
        self::assertSame(400, $challenge->getStatusCode(), (string) $challenge->getContent());
        self::assertSame('use_dpop_nonce', json_decode((string) $challenge->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        $response = $this->passkeyLogin($assertion, $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login/passkey', $nonce));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame($this->user->getEmail(), $data['user']['email']);
        $claims = self::claims($data['accessToken']);
        self::assertSame(['jkt' => $key->thumbprint()], $claims['cnf'] ?? null);
        self::assertSame($key->thumbprint(), $this->storedBinding($claims['jti']));

        $me = $this->me($data['accessToken'], $key);
        self::assertSame(200, $me->getStatusCode(), (string) $me->getContent());
        self::assertSame($this->user->getEmail(), json_decode((string) $me->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['email']);

        $loginNonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($loginNonce);
        $refresh = $this->refresh($data['refreshToken'], $key, $loginNonce);
        self::assertSame(200, $refresh->getStatusCode(), (string) $refresh->getContent());
        $rotated = json_decode((string) $refresh->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(200, $this->me($rotated['accessToken'], $key)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedPasskeyProofs(): iterable
    {
        yield 'missing proof' => ['missing'];
        yield 'proof without nonce' => ['nonce-less'];
        yield 'unissued nonce' => ['unissued-nonce'];
        yield 'proof for another endpoint' => ['wrong-uri'];
    }

    #[DataProvider('rejectedPasskeyProofs')]
    public function testPasskeyLoginRejectsAMissingOrInvalidProofWithoutIssuingTokens(string $kind): void
    {
        $key = new SignedDpopProof();
        $assertion = $this->passkeyAssertion($this->registerPasskey());
        $uri = self::ORIGIN . '/api/auth/login/passkey';
        $proof = match ($kind) {
            'missing' => null,
            'nonce-less' => $key->create('POST', $uri, 'no-access-token'),
            'unissued-nonce' => $key->createWithNonce('POST', $uri, bin2hex(random_bytes(32))),
            default => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()),
        };

        $response = $this->passkeyLogin($assertion, $proof);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('data', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]));
    }

    public function testPasskeyRefreshRejectsAProofSignedByAnotherKey(): void
    {
        $key = new SignedDpopProof();
        $assertion = $this->passkeyAssertion($this->registerPasskey());
        $response = $this->passkeyLogin($assertion, $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login/passkey', $this->nonce()));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        $stolen = $this->refresh($data['refreshToken'], new SignedDpopProof(), $this->nonce());
        self::assertSame(401, $stolen->getStatusCode(), (string) $stolen->getContent());

        $owner = $this->refresh($data['refreshToken'], $key, $this->nonce());
        self::assertSame(200, $owner->getStatusCode(), (string) $owner->getContent());
    }

    /** An existing session cannot stand in for the passkey ceremony. */
    public function testPasskeyLoginRequiresAVerifiedAssertionEvenWithAnAccessToken(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $uri = self::ORIGIN . '/api/auth/login/passkey';

        $response = $this->send('POST', '/api/auth/login/passkey', json: [
            'challengeKey' => 'grant-path-challenge',
            'response' => ['id' => 'grant-path-credential'],
        ], headers: [
            'Authorization' => 'DPoP ' . $login['accessToken'],
            'DPoP' => $key->createWithNonce('POST', $uri, $login['nonce'], $login['accessToken']),
        ]);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]));
    }

    public function testFingerprintBoundTokenAuthenticatesOnlyWithTheMatchingFingerprint(): void
    {
        $key = new SignedDpopProof();
        $fingerprint = hash('sha256', 'grant-path-device');
        $login = $this->login($key, $fingerprint);

        self::assertSame(200, $this->me($login['accessToken'], $key, $fingerprint)->getStatusCode());
        self::assertSame(401, $this->me($login['accessToken'], $key, hash('sha256', 'other-device'))->getStatusCode());
        self::assertSame(401, $this->me($login['accessToken'], $key)->getStatusCode());

        // Rotation keeps the binding.
        $refresh = $this->refresh($login['refreshToken'], $key, $login['nonce']);
        self::assertSame(200, $refresh->getStatusCode(), (string) $refresh->getContent());
        $rotated = json_decode((string) $refresh->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['accessToken'];
        self::assertSame(200, $this->me($rotated, $key, $fingerprint)->getStatusCode());
        self::assertSame(401, $this->me($rotated, $key, hash('sha256', 'other-device'))->getStatusCode());
    }

    /** First-party clients do not send a fingerprint today; their tokens stay usable. */
    public function testTokenIssuedWithoutAFingerprintIgnoresTheFingerprintHeader(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        self::assertSame(200, $this->me($login['accessToken'], $key)->getStatusCode());
        self::assertSame(200, $this->me($login['accessToken'], $key, hash('sha256', 'any-device'))->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function removedOAuthEndpoints(): iterable
    {
        yield 'token' => ['POST', '/api/oauth/token'];
        yield 'authorize' => ['GET', '/api/oauth/authorize'];
        yield 'device authorization' => ['POST', '/api/oauth/device/authorize'];
        yield 'device verification' => ['GET', '/api/oauth/device/verify'];
        yield 'device approval' => ['POST', '/api/oauth/device/approve'];
    }

    /** The RFC 8414 document advertised the removed endpoints; the path now falls through to the SPA. */
    public function testAuthorizationServerMetadataIsNotServed(): void
    {
        $response = $this->send('GET', '/.well-known/oauth-authorization-server');

        self::assertStringNotContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('token_endpoint', (string) $response->getContent());
    }

    /** Only first-party login and refresh mint tokens; the third-party OAuth endpoints are gone. */
    #[DataProvider('removedOAuthEndpoints')]
    public function testThirdPartyOAuthEndpointsAreNotRouted(string $method, string $path): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        self::assertSame(404, $this->send($method, $path)->getStatusCode());
        $authenticated = $this->send($method, $path, headers: [
            'Authorization' => 'DPoP ' . $login['accessToken'],
            'DPoP' => $key->create($method, self::ORIGIN . $path, $login['accessToken']),
        ]);
        self::assertSame(404, $authenticated->getStatusCode(), (string) $authenticated->getContent());
    }

    /** @return array{accessToken: string, refreshToken: string, nonce: string} */
    private function login(SignedDpopProof $key, ?string $fingerprint = null): array
    {
        $headers = ['DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce())];
        if ($fingerprint !== null) {
            $headers[BaanderHeader::ClientFingerprint->value] = $fingerprint;
        }
        $response = $this->send('POST', '/api/auth/login', json: [
            'email' => $this->user->getEmail(),
            'password' => self::PASSWORD,
        ], headers: $headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        return ['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken'], 'nonce' => $nonce];
    }

    private function refresh(string $refreshToken, SignedDpopProof $key, string $nonce): Response
    {
        return $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $refreshToken], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $nonce),
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

    private function me(string $accessToken, SignedDpopProof $key, ?string $fingerprint = null): Response
    {
        $headers = [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $key->create('GET', self::ORIGIN . '/api/auth/me', $accessToken),
        ];
        if ($fingerprint !== null) {
            $headers[BaanderHeader::ClientFingerprint->value] = $fingerprint;
        }

        return $this->send('GET', '/api/auth/me', headers: $headers);
    }

    private function registerPasskey(): WebAuthnTestCredential
    {
        $credential = new WebAuthnTestCredential($this->user->getId()->toString(), GrantPathOAuthKernel::RELYING_PARTY);
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
    private function passkeyLogin(array $assertion, ?string $proof): Response
    {
        return $this->send('POST', '/api/auth/login/passkey', json: $assertion, headers: $proof === null ? [] : ['DPoP' => $proof]);
    }

    private function storedBinding(string $tokenId): ?string
    {
        $binding = $this->connection->fetchOne('SELECT dpop_jkt FROM oauth_access_tokens WHERE token_id = ?', [$tokenId]);
        self::assertNotFalse($binding, 'The issued access token must be persisted.');

        return $binding;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $form
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?array $json = null, array $form = [], array $query = [], array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create(
            self::ORIGIN . $path . ($query === [] ? '' : '?' . http_build_query($query)),
            $method,
            $form,
            server: $server,
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

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
