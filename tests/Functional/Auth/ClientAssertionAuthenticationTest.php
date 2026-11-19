<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A `private_key_jwt` client authenticates at the token endpoint with a signed client
 * assertion, here through the refresh token grant, behind the DPoP proof check.
 */
final class ClientAssertionAuthenticationTest extends TestCase
{
    private const string TOKEN_URI = 'http://localhost/api/oauth/token';

    private SignedClientAssertion $clientKey;
    private SignedDpopProof $proofKey;
    private Client $keyClient;
    private string $issuer;
    private string $refreshToken;

    protected function setUp(): void
    {
        parent::setUp();

        $issuer = static::getContainer()->getParameter('auth.oauth.issuer');
        self::assertIsString($issuer);
        $this->issuer = $issuer;

        $this->clientKey = new SignedClientAssertion();
        $this->proofKey = new SignedDpopProof();
        $this->keyClient = Client::registerConfidentialWithKeys('Signing server', ['https://server.baander.app/callback'], ClientKeySource::fromJwks($this->clientKey->jwks()));
        $this->service(ClientRepositoryInterface::class)->saveClient($this->keyClient);

        $tokens = $this->service(MessageBusInterface::class)
            ->dispatch(new IssueTokenCommand($this->keyClient->getId(), $this->createTestUser()->getId(), $this->proofKey->thumbprint()))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $tokens);
        $this->refreshToken = (string) $tokens->getRefreshToken();
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
