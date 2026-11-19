<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\ClientKeySetProviderInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\ClientJwksFetcher;
use App\Auth\Infrastructure\Security\OAuth\ClientKeySetProvider;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Functional\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A `private_key_jwt` client authenticates at the token endpoint with a signed client
 * assertion, here through the refresh token grant, behind the DPoP proof check. Key sets
 * published at a `jwks_uri` come from a stubbed transport and DNS, never the network.
 * A secret client may send its secret with HTTP Basic instead of in the body.
 */
final class ClientAssertionAuthenticationTest extends TestCase
{
    private const string TOKEN_URI = 'http://localhost/api/oauth/token';

    private SignedClientAssertion $clientKey;
    private SignedDpopProof $proofKey;
    private Client $keyClient;
    private string $issuer;
    private string $refreshToken;
    /** @var list<array<string, string>> The keys the jwks_uri client publishes */
    private array $publishedKeys = [];
    private int $fetches = 0;
    private int $keySetStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $issuer = static::getContainer()->getParameter('auth.oauth.issuer');
        self::assertIsString($issuer);
        $this->issuer = $issuer;

        $this->proofKey = new SignedDpopProof();
        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        static::getContainer()->set(ClientKeySetProviderInterface::class, new ClientKeySetProvider(
            new ClientJwksFetcher(new PublicHttpClient(
                new MockHttpClient($this->publishedKeySet(...)),
                new PublicDestinationPolicy(static fn (string $host): array => $host === 'keys.baander.app' ? ['93.184.216.34'] : []),
            )),
            $cache,
            $this->service(RedisClientFactory::class),
        ));

        $this->clientKey = new SignedClientAssertion();
        $this->register(Client::registerConfidentialWithKeys('Signing server', ['https://server.baander.app/callback'], ClientKeySource::fromJwks($this->clientKey->jwks())));
    }

    private function register(Client $client): void
    {
        $this->keyClient = $client;
        $this->service(ClientRepositoryInterface::class)->saveClient($client);

        $tokens = $this->service(MessageBusInterface::class)
            ->dispatch(new IssueTokenCommand($client->getId(), $this->createTestUser()->getId(), $this->proofKey->thumbprint(), 0))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $tokens);
        $this->refreshToken = (string) $tokens->getRefreshToken();
    }

    /** Serves the key set the client currently publishes at its jwks_uri, counting the fetches. */
    private function publishedKeySet(string $method, string $url): MockResponse
    {
        self::assertSame(['GET', 'https://keys.baander.app/jwks.json'], [$method, $url]);
        ++$this->fetches;

        return new MockResponse(json_encode(['keys' => $this->publishedKeys], JSON_THROW_ON_ERROR), ['http_code' => $this->keySetStatus]);
    }

    /**
     * The proof listener answers the nonce challenge before the client is authenticated,
     * so the retry may carry the same assertion; once accepted, the assertion is used up.
     */
    public function testAnAssertionAuthenticatesTheRefreshAfterTheNonceChallengeAndOnlyOnce(): void
    {
        $assertion = $this->assertion();

        $challenge = $this->tokenRequest($this->proofKey->createWithNonce('POST', self::TOKEN_URI, 'no-such-nonce'), $assertion);
        self::assertSame(400, $challenge->getStatusCode(), (string) $challenge->getContent());
        self::assertSame('use_dpop_nonce', $this->body($challenge)['error']);

        $refreshed = $this->tokenRequest($this->proof($challenge), $assertion);
        self::assertSame(200, $refreshed->getStatusCode(), (string) $refreshed->getContent());
        $tokens = $this->body($refreshed);
        self::assertIsString($tokens['access_token']);
        self::assertIsString($tokens['refresh_token']);
        $this->refreshToken = $tokens['refresh_token'];

        // Covers AE10.
        $replayed = $this->tokenRequest($this->proof($refreshed), $assertion);
        self::assertSame(401, $replayed->getStatusCode(), (string) $replayed->getContent());
        self::assertSame('invalid_client', $this->body($replayed)['error']);
    }

    /** Covers AE9. */
    public function testAnAssertionForTheTokenEndpointUrlFails(): void
    {
        $response = $this->tokenRequest($this->proof($this->nonceChallenge()), $this->assertion(['aud' => $this->issuer . '/api/oauth/token']));

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('invalid_client', $this->body($response)['error']);
    }

    /** Covers AE12. */
    public function testAKeyClientSendingASecretFails(): void
    {
        $response = $this->send($this->proof($this->nonceChallenge()), ['client_secret' => 'a-guessed-secret']);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('invalid_client', $this->body($response)['error']);
    }

    public function testASecretClientAuthenticatesWithHttpBasic(): void
    {
        $this->register(Client::registerConfidential('Secret server', ['https://server.baander.app/callback'], ClientSecret::fromString('basic-client-secret')));

        $response = $this->sendWithBasic($this->proof($this->nonceChallenge()), 'basic-client-secret');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertIsString($this->body($response)['access_token']);
    }

    public function testASecretInHttpBasicAndInTheBodyFails(): void
    {
        $this->register(Client::registerConfidential('Secret server', ['https://server.baander.app/callback'], ClientSecret::fromString('basic-client-secret')));

        $response = $this->sendWithBasic($this->proof($this->nonceChallenge()), 'basic-client-secret', ['client_secret' => 'basic-client-secret']);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('invalid_client', $this->body($response)['error']);
        self::assertStringStartsWith('Basic ', (string) $response->headers->get('WWW-Authenticate'));
    }

    /** A client publishes a new key and signs with it, and no admin acts. */
    public function testAJwksUriClientRotatesItsKeyWithoutAnAdmin(): void
    {
        $this->publishedKeys[] = $this->clientKey->publicJwk();
        $this->register(Client::registerConfidentialWithKeys('Publishing server', ['https://server.baander.app/callback'], ClientKeySource::fromJwksUri('https://keys.baander.app/jwks.json')));

        $first = $this->tokenRequest($this->proof($this->nonceChallenge()), $this->assertion());
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $this->refreshToken = $this->body($first)['refresh_token'];

        $rotated = new SignedClientAssertion('client-key-2');
        $this->publishedKeys[] = $rotated->publicJwk();
        $this->clientKey = $rotated;

        $second = $this->tokenRequest($this->proof($first), $this->assertion());
        self::assertSame(200, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame(2, $this->fetches, 'The set was fetched once, and again for the unknown kid.');
    }

    public function testAKeySetThatCannotBeFetchedIsAnsweredWith503AndRetryAfter(): void
    {
        $this->publishedKeys[] = $this->clientKey->publicJwk();
        $this->keySetStatus = 500;
        $this->register(Client::registerConfidentialWithKeys('Failing server', ['https://server.baander.app/callback'], ClientKeySource::fromJwksUri('https://keys.baander.app/jwks.json')));

        $response = $this->tokenRequest($this->proof($this->nonceChallenge()), $this->assertion());

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertSame(1, $this->fetches);
    }

    /** @param array<string, mixed> $claims */
    private function assertion(array $claims = []): string
    {
        return $this->clientKey->create($this->keyClient->getPublicId()->toString(), $this->issuer, $claims);
    }

    /** A proof carrying the nonce the previous answer issued. */
    private function proof(Response $previous): string
    {
        $nonce = $previous->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, 'Every token endpoint answer issues the next nonce.');

        return $this->proofKey->createWithNonce('POST', self::TOKEN_URI, $nonce);
    }

    /** A request without a proof, answered with a nonce for the next one. */
    private function nonceChallenge(): Response
    {
        $this->client->request('POST', '/api/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->keyClient->getPublicId()->toString()]);

        return $this->client->getResponse();
    }

    private function tokenRequest(string $proof, string $assertion): Response
    {
        return $this->send($proof, [
            'client_assertion' => $assertion,
            'client_assertion_type' => SignedClientAssertion::TYPE,
        ]);
    }

    /** @param array<string, string> $authentication */
    private function send(string $proof, array $authentication): Response
    {
        $this->client->request('POST', '/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->keyClient->getPublicId()->toString(),
            'refresh_token' => $this->refreshToken,
            ...$authentication,
        ], server: ['HTTP_DPOP' => $proof]);

        return $this->client->getResponse();
    }

    /**
     * A refresh naming the client only in the HTTP Basic header.
     *
     * @param array<string, string> $body
     */
    private function sendWithBasic(string $proof, string $secret, array $body = []): Response
    {
        $this->client->request('POST', '/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            ...$body,
        ], server: [
            'HTTP_DPOP' => $proof,
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(rawurlencode($this->keyClient->getPublicId()->toString()) . ':' . rawurlencode($secret)),
        ]);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
