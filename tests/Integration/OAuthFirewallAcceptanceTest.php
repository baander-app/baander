<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\LeadingZeroDpopKey;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SecuritySurfaceOAuthKernel;
use App\Auth\Application\DTO\StoredWebSocketTicket;
use App\Auth\Application\DTO\WebSocketIdentity;
use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Application\Port\WebSocketTicketRedeemerInterface;
use App\Auth\Application\Port\WebSocketTicketStoreInterface;
use App\Auth\Application\Port\WebSocketTokenStatusInterface;
use App\Auth\Infrastructure\Cache\RedisWebSocketTicketStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Production firewall/authenticators/listeners and actual PostgreSQL/Redis; no TestAuthenticator. */
final class OAuthFirewallAcceptanceTest extends TestCase
{
    private static string $sharedDirectory;
    private static string $sharedPrivateKey;
    private string $directory;
    private string $privateKey;
    private ProductionOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private User $user;
    private ClientEntity $client;
    private AccessTokenEntity $token;
    private SignedDpopProof $proof;

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
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-oauth-firewall-' . bin2hex(random_bytes(8));
            self::assertTrue(mkdir(self::$sharedDirectory, 0700));
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            self::assertNotFalse($key);
            self::assertTrue(openssl_pkey_export($key, $privateKey));
            self::$sharedPrivateKey = $privateKey;
            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertSame(strlen($privateKey), file_put_contents(self::$sharedDirectory . '/private.pem', $privateKey));
            self::assertSame(strlen($details['key']), file_put_contents(self::$sharedDirectory . '/public.pem', $details['key']));
            self::assertTrue(chmod(self::$sharedDirectory . '/private.pem', 0600));
            self::assertTrue(chmod(self::$sharedDirectory . '/public.pem', 0600));
        }
        $this->directory = self::$sharedDirectory;
        $this->privateKey = self::$sharedPrivateKey;
        $this->kernel = new SecuritySurfaceOAuthKernel($this->directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::register(new Email('oauth-' . bin2hex(random_bytes(8)) . '@baander.app'), 'test-only-password', 'OAuth acceptance user');
        $users->save($this->user);
        $userEntity = $this->manager->find(UserEntity::class, $this->user->getId());
        self::assertInstanceOf(UserEntity::class, $userEntity);
        $this->client = new ClientEntity(new PublicId(), 'Disposable OAuth client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $this->proof = new SignedDpopProof();
        $this->token = new AccessTokenEntity((new Uuid())->toString(), $this->client, $userEntity, scopes: ['profile'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $this->token->setDpopJkt($this->proof->thumbprint());
        $this->manager->persist($this->client);
        $this->manager->persist($this->token);
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager, $this->user, $this->token, $this->client) && $this->manager->isOpen()) {
            $user = $this->manager->find(UserEntity::class, $this->user->getId());
            $this->manager->remove($this->token);
            $this->manager->remove($this->client);
            if ($user !== null) {
                $this->manager->remove($user);
            }
            $this->manager->flush();
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    public function testBoundProofReconstructsActualUserThroughProductionFirewall(): void
    {
        $jwt = $this->accessJwt();
        $response = $this->request($jwt, $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt));

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->user->getEmail(), $data['data']['email']);
        self::assertSame($this->user->getPublicId()->toString(), $data['data']['publicId']);
        self::assertArrayNotHasKey('uuid', $data['data']);
        self::assertStringNotContainsString($this->user->getId()->toString(), $response->getContent());
    }

    public function testInternalUuidSubjectIsRejectedDespiteValidSignatureAndProof(): void
    {
        $jwt = $this->accessJwt(subject: $this->user->getId()->toString());
        $response = $this->request($jwt, $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt));

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('AUTH_INVALID_TOKEN', $data['error']['code']);
    }

    public function testLeadingZeroEcCoordinateWorksThroughProductionFirewall(): void
    {
        $this->proof = new SignedDpopProof(LeadingZeroDpopKey::PEM);
        $this->token->setDpopJkt($this->proof->thumbprint());
        $this->manager->flush();
        $jwt = $this->accessJwt();
        $response = $this->request($jwt, $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testMalformedEcPointIsRejectedWith403ThroughProductionFirewall(): void
    {
        $jwt = $this->accessJwt();
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => SignedDpopProof::encode("\x01"), 'y' => SignedDpopProof::encode("\x02")];
        $response = $this->request($jwt, $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt, $jwk));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidPresentations(): iterable
    {
        yield 'missing proof' => ['missing', 403];
        yield 'different proof key' => ['wrong-key', 403];
        yield 'wrong audience' => ['audience', 401];
        yield 'revoked token' => ['revoked', 401];
        yield 'HTTPS proof on HTTP request' => ['scheme', 403];
    }

    #[DataProvider('invalidPresentations')]
    public function testProductionFirewallRejectsInvalidPresentation(string $kind, int $status): void
    {
        if ($kind === 'revoked') {
            $this->token->revoke();
            $this->manager->flush();
        }
        $jwt = $this->accessJwt($kind === 'audience' ? 'https://other.baander.app' : 'https://baander.app');
        $proof = $kind === 'wrong-key' ? new SignedDpopProof() : $this->proof;
        $header = $kind === 'missing' ? null : $proof->create('GET', 'https://baander.app/api/auth/me', $jwt);
        $response = $this->request($jwt, $header, $kind === 'scheme' ? 'http://baander.app/api/auth/me' : 'https://baander.app/api/auth/me');

        self::assertSame($status, $response->getStatusCode(), $response->getContent());
    }

    public function testProofReplayIsRejectedThroughProductionRedisCache(): void
    {
        $jwt = $this->accessJwt();
        $proof = $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt);

        self::assertSame(200, $this->request($jwt, $proof)->getStatusCode());
        self::assertSame(403, $this->request($jwt, $proof)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function profileMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'PUT' => ['PUT'];
    }

    #[DataProvider('profileMethods')]
    public function testRevocationRefusesTheNextRequestIncludingAlternateMethods(string $method): void
    {
        $jwt = $this->accessJwt();
        self::assertSame(200, $this->request($jwt, $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt))->getStatusCode());
        $this->token->revoke();
        $this->manager->flush();

        $response = $this->request($jwt, $this->proof->create($method, 'https://baander.app/api/auth/me', $jwt), method: $method);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        $users = $this->kernel->getContainer()->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        self::assertSame('OAuth acceptance user', $users->findByUuid($this->user->getId())?->getName());
    }

    #[DataProvider('profileMethods')]
    public function testAProofForAnotherMethodCannotReadOrUpdateTheProfile(string $method): void
    {
        $jwt = $this->accessJwt();
        $proofMethod = $method === 'GET' ? 'PUT' : 'GET';
        $response = $this->request($jwt, $this->proof->create($proofMethod, 'https://baander.app/api/auth/me', $jwt), method: $method);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    /** @return iterable<string, array{string, string}> */
    public static function protectedRoutes(): iterable
    {
        yield 'user administration read' => ['GET', '/api/admin/users'];
        yield 'user administration write' => ['POST', '/api/admin/users'];
        yield 'library read' => ['GET', '/api/libraries'];
        yield 'library create' => ['POST', '/api/libraries'];
        yield 'playlist read' => ['GET', '/api/playlists/'];
        yield 'playlist create' => ['POST', '/api/playlists/'];
        yield 'server registration' => ['POST', '/api/discovery/register'];
        yield 'pairing code' => ['POST', '/api/discovery/pairing-code'];
        yield 'pairing completion' => ['POST', '/api/discovery/complete-pairing'];
        yield 'pairing QR payload' => ['GET', '/api/discovery/qr-payload/' . '00000000-0000-7000-8000-000000000001'];
    }

    #[DataProvider('protectedRoutes')]
    public function testAnonymousAndRevokedCredentialsCannotReachProtectedRoutes(string $method, string $path): void
    {
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());

        $this->token->revoke();
        $this->manager->flush();
        $jwt = $this->accessJwt();
        $response = $this->request($jwt, $this->proof->create($method, $uri, $jwt), $uri, $method);
        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAMemberCannotAdministerUsersOrRegisterAServer(): void
    {
        $jwt = $this->accessJwt();
        foreach ([['GET', '/api/admin/users'], ['POST', '/api/admin/users'], ['POST', '/api/discovery/register']] as [$method, $path]) {
            $uri = 'https://baander.app' . $path;
            $response = $this->request($jwt, $this->proof->create($method, $uri, $jwt), $uri, $method);
            self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        }
    }

    public function testABoundTokenUnderTheBearerSchemeCannotAuthenticateTheHttpApi(): void
    {
        $jwt = $this->accessJwt();
        $request = Request::create('https://baander.app/api/auth/me');
        $request->headers->set('Authorization', 'Bearer ' . $jwt);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testProfileAndFailureResponsesAndApplicationLogsDoNotExposePresentedCredentials(): void
    {
        $logger = $this->kernel->getContainer()->get('oauth.acceptance.auth_logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $marker = 'Security response logging check ' . bin2hex(random_bytes(8));
        $logger->warning($marker);
        $jwt = $this->accessJwt();
        $proof = $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt);
        $response = $this->request($jwt, $proof);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['publicId', 'name', 'email', 'emailVerifiedAt', 'createdAt', 'roles'], array_keys($data['data']));
        $failure = $this->request($jwt, $proof);
        self::assertSame(403, $failure->getStatusCode());
        foreach ([$response, $failure] as $result) {
            $serialized = (string) $result->getContent() . $result->headers;
            foreach ([$jwt, $proof, 'test-only-password'] as $credential) {
                self::assertStringNotContainsString($credential, $serialized);
            }
            self::assertFalse($result->headers->has('Location'));
        }
        $logs = glob($this->kernel->getLogDir() . '/*.log');
        self::assertIsArray($logs);
        self::assertNotEmpty($logs);
        $markerRecorded = false;
        foreach ($logs as $path) {
            $log = file_get_contents($path);
            self::assertIsString($log);
            $markerRecorded = $markerRecorded || str_contains($log, $marker);
            self::assertStringNotContainsString($jwt, $log);
            self::assertStringNotContainsString($proof, $log);
        }
        self::assertTrue($markerRecorded, 'The production auth logger must write to the inspected files.');
    }

    public function testTicketIssuanceRequiresProofPossessionAndRejectsAReplayedProof(): void
    {
        $jwt = $this->accessJwt();
        $uri = 'https://baander.app/api/ws/tickets';
        self::assertSame(403, $this->request($jwt, null, $uri, 'POST', 'https://baander.app')->getStatusCode());
        $wrongKey = new SignedDpopProof();
        self::assertSame(403, $this->request($jwt, $wrongKey->create('POST', $uri, $jwt), $uri, 'POST', 'https://baander.app')->getStatusCode());
        $proof = $this->proof->create('POST', $uri, $jwt);
        $response = $this->request($jwt, $proof, $uri, 'POST', 'https://baander.app');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $data['ticket']);
        $expiry = new \DateTimeImmutable($data['expiresAt']);
        self::assertGreaterThan(time(), $expiry->getTimestamp());
        self::assertLessThanOrEqual(time() + 30, $expiry->getTimestamp());
        self::assertSame(403, $this->request($jwt, $proof, $uri, 'POST', 'https://baander.app')->getStatusCode());
    }

    public function testTicketIssuanceRejectsMissingNullAndForeignOrigins(): void
    {
        $jwt = $this->accessJwt();
        $uri = 'https://baander.app/api/ws/tickets';
        foreach ([null, 'null', 'https://foreign.baander.app', 'https://baander.app/'] as $origin) {
            $response = $this->request($jwt, $this->proof->create('POST', $uri, $jwt), $uri, 'POST', $origin);
            self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        }
    }

    public function testActualRedisPreservesBindingMismatchAndConsumesATicketOnlyOnce(): void
    {
        $ticket = $this->issueTicket();
        $store = $this->kernel->getContainer()->get('oauth.acceptance.websocket_store');
        self::assertInstanceOf(WebSocketTicketStoreInterface::class, $store);
        // Exercise the adapter itself: policy rejection alone would not prove Lua preserves the record.
        self::assertNull($store->consume($ticket, 'https://foreign.baander.app', '/api/ws'));
        self::assertNull($store->consume($ticket, 'https://baander.app', '/api/other'));
        $identity = $this->redeemer()->redeem($ticket, 'https://baander.app', '/api/ws');
        self::assertNotNull($identity);
        self::assertSame($this->user->getId()->toString(), $identity->userId);
        self::assertSame($this->token->getTokenId(), $identity->accessTokenId);
        self::assertNull($this->redeemer()->redeem($ticket, 'https://baander.app', '/api/ws'));
    }

    public function testActualRedisExpiresTheTicketIndependentlyOfTheApplicationClock(): void
    {
        $ticket = bin2hex(random_bytes(32));
        $store = $this->kernel->getContainer()->get('oauth.acceptance.websocket_store');
        self::assertInstanceOf(WebSocketTicketStoreInterface::class, $store);
        $store->put($ticket, new StoredWebSocketTicket(
            new WebSocketIdentity($this->user->getId()->toString(), $this->token->getTokenId()),
            'https://baander.app', '/api/ws', new \DateTimeImmutable('-1 second'),
        ));
        self::assertNull($store->consume($ticket, 'https://baander.app', '/api/ws'));
    }

    /** @return iterable<string, array{'revoked'|'disabled'|'expired'|'unbound'}> */
    public static function ticketAuthorizationChanges(): iterable
    {
        yield 'token revoked' => ['revoked'];
        yield 'user disabled' => ['disabled'];
        yield 'token expired' => ['expired'];
        yield 'proof binding removed' => ['unbound'];
    }

    /** @param 'revoked'|'disabled'|'expired'|'unbound' $change */
    #[DataProvider('ticketAuthorizationChanges')]
    public function testTicketRedemptionReadsCommittedAuthorizationDespiteStaleManagedEntities(string $change): void
    {
        $ticket = $this->issueTicket();
        $user = $this->manager->find(UserEntity::class, $this->user->getId());
        self::assertInstanceOf(UserEntity::class, $user);
        self::assertFalse($this->token->isRevoked());
        self::assertFalse($user->isDisabled());
        $external = DriverManager::getConnection($this->manager->getConnection()->getParams());
        try {
            match ($change) {
                'revoked' => $external->executeStatement('UPDATE oauth_access_tokens SET revoked=true WHERE token_id=?', [$this->token->getTokenId()]),
                'disabled' => $external->executeStatement('UPDATE users SET disabled=true WHERE id=?', [$this->user->getId()->toString()]),
                'expired' => $external->executeStatement("UPDATE oauth_access_tokens SET expires_at=clock_timestamp() - INTERVAL '1 second' WHERE token_id=?", [$this->token->getTokenId()]),
                'unbound' => $external->executeStatement('UPDATE oauth_access_tokens SET dpop_jkt=NULL WHERE token_id=?', [$this->token->getTokenId()]),
            };
            self::assertFalse($this->token->isRevoked(), 'The identity map must retain its stale token for this regression.');
            self::assertFalse($user->isDisabled(), 'The identity map must retain its stale account for this regression.');
            self::assertNull($this->redeemer()->redeem($ticket, 'https://baander.app', '/api/ws'));
        } finally {
            $external->close();
        }
    }

    public function testTicketAuthorizationRequiresTheTokenOwnerAndCapsExpiry(): void
    {
        $status = $this->kernel->getContainer()->get('oauth.acceptance.websocket_status');
        self::assertInstanceOf(WebSocketTokenStatusInterface::class, $status);
        self::assertNull($status->expiresAt(Uuid::v7()->toString(), $this->token->getTokenId()));
        $this->token->setExpiryDateTime(new \DateTimeImmutable('+8 seconds'));
        $this->manager->flush();
        $jwt = $this->accessJwt();
        $uri = 'https://baander.app/api/ws/tickets';
        $response = $this->request($jwt, $this->proof->create('POST', $uri, $jwt), $uri, 'POST', 'https://baander.app');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertLessThanOrEqual($this->token->getExpiryDateTime()->getTimestamp(), (new \DateTimeImmutable($data['expiresAt']))->getTimestamp());
    }

    public function testUnavailableActualRedisCannotRedeemAnIdentity(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        $port = substr($address, strrpos($address, ':') + 1);
        fclose($listener);
        $store = new RedisWebSocketTicketStore(new RedisClientFactory('redis://127.0.0.1:' . $port));
        $started = microtime(true);
        try {
            $store->consume(str_repeat('a', 64), 'https://baander.app', '/api/ws');
            self::fail('Unavailable Redis must not supply a ticket identity.');
        } catch (WebSocketTicketUnavailableException) {
            self::assertLessThan(5.0, microtime(true) - $started);
        }
    }

    private function issueTicket(): string
    {
        $jwt = $this->accessJwt();
        $uri = 'https://baander.app/api/ws/tickets';
        $response = $this->request($jwt, $this->proof->create('POST', $uri, $jwt), $uri, 'POST', 'https://baander.app');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['ticket'];
    }

    private function redeemer(): WebSocketTicketRedeemerInterface
    {
        $redeemer = $this->kernel->getContainer()->get('oauth.acceptance.websocket_redeemer');
        self::assertInstanceOf(WebSocketTicketRedeemerInterface::class, $redeemer);

        return $redeemer;
    }

    private function accessJwt(string $audience = 'https://baander.app', ?string $subject = null): string
    {
        $claims = [
            'jti' => $this->token->getTokenId(),
            'sub' => $subject ?? $this->user->getPublicId()->toString(),
            'aud' => $audience,
            'client_id' => $this->client->getIdentifier(),
            'scopes' => ['profile'],
            'iat' => time(),
            'nbf' => time() - 1,
            'exp' => time() + 3600,
            'cnf' => ['jkt' => $this->proof->thumbprint()],
        ];
        $body = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($body, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return $body . '.' . SignedDpopProof::encode($signature);
    }

    private function request(string $jwt, ?string $proof, string $uri = 'https://baander.app/api/auth/me', string $method = 'GET', ?string $origin = null): Response
    {
        $request = Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"Changed by request"}');
        $request->headers->set('Authorization', 'DPoP ' . $jwt);
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }
        if ($proof !== null) {
            $request->headers->set('DPoP', $proof);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
