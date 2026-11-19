<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * `/api/auth/refresh` rotates only the first-party client's refresh tokens. Any other
 * client refreshes at the token endpoint, where it authenticates as itself.
 */
final class RefreshEndpointTest extends TestCase
{
    private const string REFRESH_URI = 'http://localhost/api/auth/refresh';
    private const string TOKEN_URI = 'http://localhost/api/oauth/token';

    private SignedDpopProof $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = new SignedDpopProof();
    }

    public function testAFirstPartyRefreshTokenRotatesAtTheFirstPartyEndpoint(): void
    {
        $client = FirstPartyOAuthClient::entity(static::getContainer(), $this->entityManager);
        $tokens = $this->issue($client->getId());

        $response = $this->refresh((string) $tokens->getRefreshToken());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertIsString($this->json($response)['data']['refreshToken'] ?? null);
    }

    public function testADelegatedRefreshTokenIsRefusedAtTheFirstPartyEndpointAndRotatesAtTheTokenEndpoint(): void
    {
        $owner = $this->createTestUser();
        $client = Client::createPersonalAccess('Owner CLI', $owner->getId());
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);
        $tokens = $this->issue($client->getId(), $owner->getId());

        $refused = $this->refresh((string) $tokens->getRefreshToken());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $refused->getStatusCode(), (string) $refused->getContent());

        // The refusal consumed nothing: the token still rotates where its client authenticates.
        $this->client->request('POST', '/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getPublicId()->toString(),
            'refresh_token' => (string) $tokens->getRefreshToken(),
        ], [], ['HTTP_DPOP' => $this->key->createWithNonce('POST', self::TOKEN_URI, $this->nonce())]);
        $rotated = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $rotated->getStatusCode(), (string) $rotated->getContent());
        self::assertIsString($this->json($rotated)['refresh_token'] ?? null);
    }

    private function issue(Uuid $clientId, ?Uuid $userId = null): TokenResponseDTO
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $tokens = $bus
            ->dispatch(new IssueTokenCommand($clientId, $userId ?? $this->createTestUser()->getId(), $this->key->thumbprint()))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $tokens);

        return $tokens;
    }

    private function refresh(string $refreshToken): Response
    {
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_DPOP' => $this->key->createWithNonce('POST', self::REFRESH_URI, $this->nonce()),
        ], json_encode(['refreshToken' => $refreshToken], JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /** A proof without a nonce is answered with a fresh one; nonces work at either endpoint. */
    private function nonce(): string
    {
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_DPOP' => (new SignedDpopProof())->create('POST', self::REFRESH_URI, 'no-access-token'),
        ], json_encode(['refreshToken' => 'nonce-probe-' . bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR));
        $nonce = $this->client->getResponse()->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, (string) $this->client->getResponse()->getContent());

        return $nonce;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
