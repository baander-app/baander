<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\GrantPathOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token-minting entry points through the production firewall, with real PostgreSQL and Redis.
 *
 * Login and refresh run through IssueTokenHandler (direct_grant) and RefreshTokenHandler.
 * Every other grant is League's; its token endpoint sits behind the authenticated `^/api/`
 * access rule, so redemption checks are exercised on League's server directly.
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
    private ClientEntity $thirdPartyClient;
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
        $this->thirdPartyClient = new ClientEntity(new PublicId(), 'Grant path third party', json_encode([self::REDIRECT], JSON_THROW_ON_ERROR));
        $this->manager->persist($this->thirdPartyClient);
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->user)) {
            // Refresh tokens and token metadata cascade from access tokens; auth codes cascade from the user.
            $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]);
            if (isset($this->thirdPartyClient)) {
                $this->connection->executeStatement('DELETE FROM oauth_clients WHERE id = ?', [$this->thirdPartyClient->getId()->toString()]);
            }
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

    /**
     * PasskeyAuthenticator is not registered on the production firewall, so the passkey
     * login controller only sees callers who already present an access token. Its token
     * pair carries no proof-key binding, so neither token can be used or refreshed.
     */
    public function testPasskeyLoginMintsOnlyUnusableTokensForExistingSessions(): void
    {
        $assertion = ['challengeKey' => 'grant-path-challenge', 'response' => ['id' => 'grant-path-credential']];
        $anonymous = $this->send('POST', '/api/auth/login/passkey', json: $assertion);
        self::assertSame(401, $anonymous->getStatusCode(), (string) $anonymous->getContent());

        $key = new SignedDpopProof();
        $login = $this->login($key);
        $response = $this->send('POST', '/api/auth/login/passkey', json: $assertion, headers: [
            'Authorization' => 'DPoP ' . $login['accessToken'],
            'DPoP' => $key->create('POST', self::ORIGIN . '/api/auth/login/passkey', $login['accessToken']),
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $claims = self::claims($data['accessToken']);
        self::assertArrayNotHasKey('cnf', $claims);
        self::assertNull($this->storedBinding($claims['jti']));

        $refresh = $this->refresh($data['refreshToken'], $key, $login['nonce']);
        self::assertSame(401, $refresh->getStatusCode(), (string) $refresh->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function leagueGrants(): iterable
    {
        yield 'authorization code' => ['authorization_code'];
        yield 'client credentials' => ['client_credentials'];
        yield 'refresh token' => ['refresh_token'];
        yield 'device code' => ['urn:ietf:params:oauth:grant-type:device_code'];
    }

    #[DataProvider('leagueGrants')]
    public function testTokenEndpointRejectsAnonymousCallersForEveryLeagueGrant(string $grantType): void
    {
        $key = new SignedDpopProof();
        $response = $this->send('POST', '/api/oauth/token', form: [
            'grant_type' => $grantType,
            'client_id' => $this->thirdPartyClient->getIdentifier(),
        ], headers: ['DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/oauth/token', $this->nonce())]);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    /** Characterizes the gate: the firewall's DPoP check stores the proof jti, so the endpoint sees a replay. */
    public function testTokenEndpointRejectsAuthenticatedCallersBecauseTheFirewallConsumesTheProof(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $uri = self::ORIGIN . '/api/oauth/token';

        $response = $this->send('POST', '/api/oauth/token', form: [
            'grant_type' => 'client_credentials',
            'client_id' => $this->thirdPartyClient->getIdentifier(),
        ], headers: [
            'Authorization' => 'DPoP ' . $login['accessToken'],
            'DPoP' => $key->createWithNonce('POST', $uri, $login['nonce'], $login['accessToken']),
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('use_dpop_nonce', $body['error']);
        self::assertStringContainsString('"jti" has been reused', $body['error_description']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function nonS256Challenges(): iterable
    {
        yield 'missing challenge' => [[]];
        yield 'explicit plain' => [['code_challenge' => self::challenge(self::verifier()), 'code_challenge_method' => 'plain']];
        yield 'empty method' => [['code_challenge' => self::challenge(self::verifier()), 'code_challenge_method' => '']];
    }

    /** @param array<string, string> $pkce */
    #[DataProvider('nonS256Challenges')]
    public function testAuthorizeRejectsRequestsWithoutAnS256Challenge(array $pkce): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);

        $response = $this->authorize($key, $login['accessToken'], $pkce);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($response->headers->get('Location'));
    }

    public function testAuthorizationCodeRedemptionEnforcesTheS256VerifierFromTheEncryptedPayload(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $verifier = self::verifier();
        $code = $this->authorizationCode($key, $login['accessToken'], [
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        // League keeps the challenge only in the encrypted code; the persisted row never receives it.
        $payload = $this->decryptCode($code);
        self::assertSame(['S256', self::challenge($verifier)], [$payload['code_challenge_method'], $payload['code_challenge']]);
        self::assertSame(
            ['code_challenge' => null, 'code_challenge_method' => null],
            $this->connection->fetchAssociative(
                'SELECT code_challenge, code_challenge_method FROM oauth_auth_codes WHERE code_id = ?',
                [$payload['auth_code_id']],
            ),
        );

        self::assertSame('invalid_request', $this->redemptionError($code, null));
        self::assertSame('invalid_grant', $this->redemptionError($code, self::verifier()));
        self::assertNull($this->redemptionError($code, $verifier));
        self::assertSame('invalid_grant', $this->redemptionError($code, $verifier), 'A redeemed code is single-use.');
    }

    /**
     * Characterizes a latent downgrade: the controller defaults an absent method to S256,
     * while League defaults it to `plain` and accepts the challenge itself as the verifier.
     */
    public function testAuthorizeWithoutAChallengeMethodIssuesAPlainPkceCode(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login($key);
        $challenge = self::challenge(self::verifier());
        $code = $this->authorizationCode($key, $login['accessToken'], ['code_challenge' => $challenge]);

        self::assertSame('plain', $this->decryptCode($code)['code_challenge_method']);
        self::assertNull($this->redemptionError($code, $challenge));
    }

    /** @return array{accessToken: string, refreshToken: string, nonce: string} */
    private function login(SignedDpopProof $key): array
    {
        $response = $this->send('POST', '/api/auth/login', json: [
            'email' => $this->user->getEmail(),
            'password' => self::PASSWORD,
        ], headers: ['DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce())]);
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

    /** @param array<string, string> $pkce */
    private function authorize(SignedDpopProof $key, string $accessToken, array $pkce): Response
    {
        return $this->send('GET', '/api/oauth/authorize', query: [
            'response_type' => 'code',
            'client_id' => $this->thirdPartyClient->getIdentifier(),
            'redirect_uri' => self::REDIRECT,
            'scope' => 'profile',
            'state' => 'grant-path-state',
            ...$pkce,
        ], headers: [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $key->create('GET', self::ORIGIN . '/api/oauth/authorize', $accessToken),
        ]);
    }

    /** @param array<string, string> $pkce */
    private function authorizationCode(SignedDpopProof $key, string $accessToken, array $pkce): string
    {
        $response = $this->authorize($key, $accessToken, $pkce);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        $location = $response->headers->get('Location');
        self::assertIsString($location);
        self::assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    /** @return array<string, mixed> */
    private function decryptCode(string $code): array
    {
        $encryptionKey = $this->kernel->getContainer()->getParameter('auth.encryption_key');
        self::assertIsString($encryptionKey);

        return json_decode(Crypto::decrypt($code, Key::loadFromAsciiSafeString($encryptionKey)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Redeems on League's server behind the firewall gate; returns the OAuth error code, or null on success. */
    private function redemptionError(string $code, ?string $verifier): ?string
    {
        $server = $this->kernel->getContainer()->get('oauth.acceptance.authorization_server');
        self::assertInstanceOf(AuthorizationServer::class, $server);
        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->thirdPartyClient->getIdentifier(),
            'redirect_uri' => self::REDIRECT,
            'code' => $code,
        ];
        if ($verifier !== null) {
            $body['code_verifier'] = $verifier;
        }

        try {
            $response = $server->respondToAccessTokenRequest(
                (new ServerRequest('POST', self::ORIGIN . '/api/oauth/token'))->withParsedBody($body),
                new Psr7Response(),
            );
        } catch (OAuthServerException $exception) {
            return $exception->getErrorType();
        } finally {
            $this->manager->clear();
        }
        self::assertSame(200, $response->getStatusCode());

        return null;
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

    private static function verifier(): string
    {
        return SignedDpopProof::encode(random_bytes(32));
    }

    private static function challenge(string $verifier): string
    {
        return SignedDpopProof::encode(hash('sha256', $verifier, true));
    }
}
