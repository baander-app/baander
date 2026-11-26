<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pushed authorization request endpoint (RFC 9126) through the kernel, with PostgreSQL
 * and Redis: the client authenticates, binds the request to a DPoP key with a proof, the
 * dpop_jkt parameter or both (RFC 9449 section 10.1), and receives a request URI.
 */
final class PushedAuthorizationRequestEndpointTest extends RateLimitTestCase
{
    private const string URI = 'http://localhost/api/oauth/par';
    private const string REDIRECT = 'https://player.baander.app/callback';
    // RFC 7636 appendix B.
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testAPushWithAProofAnswersARequestUriAndTheNextNonce(): void
    {
        $key = new SignedDpopProof();
        $clientId = $this->publicClient();

        $response = $this->push($this->request($clientId), $key->createWithNonce('POST', self::URI, $this->nonce()));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['request_uri', 'expires_in'], array_keys($body));
        self::assertMatchesRegularExpression('#^urn:ietf:params:oauth:request_uri:[A-Za-z0-9._~-]{80}$#', $body['request_uri']);
        self::assertSame(300, $body['expires_in']);
        self::assertIsString($response->headers->get('DPoP-Nonce'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($key->thumbprint(), $this->storedThumbprint($body['request_uri']));
    }

    public function testAPushWithOnlyTheDpopJktParameterStoresThatThumbprint(): void
    {
        $key = new SignedDpopProof();

        $response = $this->push([...$this->request($this->publicClient()), 'dpop_jkt' => $key->thumbprint()]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($response->headers->has('DPoP-Nonce'), 'Only a verified proof earns a nonce.');
        self::assertSame($key->thumbprint(), $this->storedThumbprint($this->json($response)['request_uri']));
    }

    public function testAPushWithoutAKeyBindingIsRejected(): void
    {
        $this->assertOAuthError('invalid_request', $this->push($this->request($this->publicClient())));
    }

    public function testAProofAndADifferentDpopJktAreRejected(): void
    {
        $key = new SignedDpopProof();
        $other = new SignedDpopProof();

        $response = $this->push(
            [...$this->request($this->publicClient()), 'dpop_jkt' => $other->thumbprint()],
            $key->createWithNonce('POST', self::URI, $this->nonce()),
        );

        $this->assertOAuthError('invalid_dpop_proof', $response);
        self::assertIsString($response->headers->get('DPoP-Nonce'), 'The verified proof still earns the next nonce.');
    }

    public function testAMalformedDpopJktIsRejected(): void
    {
        $clientId = $this->publicClient();

        $this->assertOAuthError('invalid_request', $this->push([...$this->request($clientId), 'dpop_jkt' => 'too-short']));
        $this->assertOAuthError('invalid_request', $this->push([...$this->request($clientId), 'dpop_jkt' => str_repeat('+', 43)]));
    }

    public function testAProofWithoutANonceIsChallengedAndTheRetryWithItSucceeds(): void
    {
        $key = new SignedDpopProof();
        $clientId = $this->publicClient();

        $challenge = $this->push($this->request($clientId), $key->create('POST', self::URI, 'no-access-token'));
        $this->assertOAuthError('use_dpop_nonce', $challenge);
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        $retry = $this->push($this->request($clientId), $key->createWithNonce('POST', self::URI, $nonce));
        self::assertSame(Response::HTTP_CREATED, $retry->getStatusCode(), (string) $retry->getContent());
    }

    public function testAConfidentialClientWithAWrongSecretIsRejected(): void
    {
        $client = Client::create('PAR server app', [self::REDIRECT], secret: ClientSecret::fromString('par-shared-secret'), confidential: true);
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);
        $request = [...$this->request($client->getPublicId()->toString()), 'dpop_jkt' => (new SignedDpopProof())->thumbprint()];

        $this->assertOAuthError('invalid_client', $this->push([...$request, 'client_secret' => 'wrong']), Response::HTTP_UNAUTHORIZED);
        self::assertSame(Response::HTTP_CREATED, $this->push([...$request, 'client_secret' => 'par-shared-secret'])->getStatusCode());
    }

    public function testADeviceClientAndInvalidParametersKeepTheirErrors(): void
    {
        $jkt = (new SignedDpopProof())->thumbprint();
        $device = Client::create('PAR TV', [self::REDIRECT], deviceClient: true);
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($device);
        $request = [...$this->request($this->publicClient()), 'dpop_jkt' => $jkt];

        $this->assertOAuthError('unauthorized_client', $this->push([...$request, 'client_id' => $device->getPublicId()->toString()]));
        $this->assertOAuthError('invalid_request', $this->push([...$request, 'code_challenge_method' => 'plain']));
        $withoutChallenge = array_diff_key($request, ['code_challenge' => true]);
        $this->assertOAuthError('invalid_request', $this->push($withoutChallenge));
        $this->assertOAuthError('invalid_request', $this->push([...$request, 'redirect_uri' => 'https://evil.baander.app/callback']));
    }

    public function testRequestUriAndRequestObjectParametersAreRefused(): void
    {
        $request = [...$this->request($this->publicClient()), 'dpop_jkt' => (new SignedDpopProof())->thumbprint()];

        $this->assertOAuthError('invalid_request', $this->push([...$request, 'request_uri' => 'urn:ietf:params:oauth:request_uri:abc']));
        $this->assertOAuthError('request_not_supported', $this->push([...$request, 'request' => 'header.payload.signature']));
    }

    public function testAPushWithoutAClientIdIsRejected(): void
    {
        $request = [...$this->request($this->publicClient()), 'dpop_jkt' => (new SignedDpopProof())->thumbprint()];
        unset($request['client_id']);

        $this->assertOAuthError('invalid_request', $this->push($request));
    }

    public function testAStateOfAtMost1024BytesIsAccepted(): void
    {
        $clientId = $this->publicClient();
        $jkt = (new SignedDpopProof())->thumbprint();

        $accepted = $this->push([...$this->request($clientId), 'dpop_jkt' => $jkt, 'state' => str_repeat('s', 1024)]);
        self::assertSame(Response::HTTP_CREATED, $accepted->getStatusCode(), (string) $accepted->getContent());

        $this->assertOAuthError('invalid_request', $this->push([...$this->request($clientId), 'dpop_jkt' => $jkt, 'state' => str_repeat('s', 1025)]));
    }

    public function testABodyOver64KilobytesIsRefusedWith413(): void
    {
        $body = json_encode([
            ...$this->request($this->publicClient()),
            'dpop_jkt' => (new SignedDpopProof())->thumbprint(),
            'padding' => str_repeat('p', 100 * 1024),
        ], JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/api/oauth/par', [], [], ['REMOTE_ADDR' => '198.51.100.90', 'CONTENT_TYPE' => 'application/json'], $body);
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM oauth_pushed_authorization_requests'));
    }

    public function testAPublicClientDrainedFromOtherNetworksStillAcceptsAPushFromAnother(): void
    {
        $clientId = $this->publicClient();
        // Anyone can name a public client and sign their own proofs; they drain only
        // the allowance of the networks they send from.
        $this->exhaust('oauth_par_client', $clientId . '|198.51.100.0');
        $this->exhaust('oauth_par_client', $clientId . '|192.0.2.0');
        $request = [...$this->request($clientId), 'dpop_jkt' => (new SignedDpopProof())->thumbprint()];

        $this->assertTooManyRequests($this->push($request, ip: '198.51.100.66'));
        $this->trackLimiter('oauth_par_client', $clientId . '|203.0.113.0');
        self::assertSame(Response::HTTP_CREATED, $this->push($request, ip: '203.0.113.66')->getStatusCode());
    }

    public function testAConfidentialClientIsChargedOnlyByPushesThatAuthenticateAsIt(): void
    {
        $client = Client::create('PAR server app', [self::REDIRECT], secret: ClientSecret::fromString('par-shared-secret'), confidential: true);
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);
        $clientId = $client->getPublicId()->toString();
        $this->trackLimiter('oauth_par_client', $clientId);
        $request = [...$this->request($clientId), 'dpop_jkt' => (new SignedDpopProof())->thumbprint()];

        $this->assertOAuthError('invalid_client', $this->push([...$request, 'client_secret' => 'wrong']), Response::HTTP_UNAUTHORIZED);
        self::assertSame(10000, $this->remaining('oauth_par_client', $clientId), 'A failed authentication charges no one.');

        self::assertSame(Response::HTTP_CREATED, $this->push([...$request, 'client_secret' => 'par-shared-secret'])->getStatusCode());
        self::assertSame(9999, $this->remaining('oauth_par_client', $clientId));
    }

    private function remaining(string $limiter, string $key): int
    {
        return $this->limiter($limiter)->create($key)->consume(0)->getRemainingTokens();
    }

    /** @return array<string, string> */
    private function request(string $clientId): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'library',
            'state' => 'par-state',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ];
    }

    /** @param array<string, string> $form */
    private function push(array $form, ?string $proof = null, string $ip = '198.51.100.90'): Response
    {
        $server = ['REMOTE_ADDR' => $ip];
        if ($proof !== null) {
            $server['HTTP_DPOP'] = $proof;
        }
        $this->client->request('POST', '/api/oauth/par', $form, [], $server);

        return $this->client->getResponse();
    }

    /** A nonce-less proof is answered with a fresh nonce. */
    private function nonce(): string
    {
        $response = $this->push($this->request('nonce_probe_client_0001'), (new SignedDpopProof())->create('POST', self::URI, 'no-access-token'));
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, (string) $response->getContent());

        return $nonce;
    }

    private function publicClient(): string
    {
        $client = Client::create('PAR player', [self::REDIRECT]);
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);

        return $client->getPublicId()->toString();
    }

    private function storedThumbprint(string $requestUri): string|false
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT dpop_jkt FROM oauth_pushed_authorization_requests WHERE request_id = ?',
            [substr($requestUri, strlen('urn:ietf:params:oauth:request_uri:'))],
        );
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertOAuthError(string $error, Response $response, int $status = Response::HTTP_BAD_REQUEST): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($error, $this->json($response)['error']);
        self::assertArrayNotHasKey('redirect_uri', $this->json($response));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
