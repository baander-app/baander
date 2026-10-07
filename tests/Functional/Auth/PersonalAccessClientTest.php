<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** Personal access clients against PostgreSQL, the command bus and League's resource server. */
final class PersonalAccessClientTest extends TestCase
{
    private const string DPOP_JKT = 'personal-access-client-test-jkt';

    public function testOwnerCreatesAndListsOnlyOwnClients(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();

        $first = $this->createPersonalAccessClient($owner, 'Owner CLI');
        $this->createPersonalAccessClient($owner, 'Owner scripts');
        $foreign = $this->createPersonalAccessClient($other, 'Other CLI');

        self::assertSame('Owner CLI', $first['name']);
        self::assertTrue($first['personalAccessClient']);
        self::assertFalse($first['confidential']);
        self::assertNull($first['secret']);

        $ownerClients = $this->listClients($owner);
        self::assertEqualsCanonicalizing(['Owner CLI', 'Owner scripts'], array_column($ownerClients, 'name'));
        self::assertNotContains($foreign['publicId'], array_column($ownerClients, 'publicId'));
        self::assertSame([$foreign['publicId']], array_column($this->listClients($other), 'publicId'));
    }

    public function testBlankNameIsRejected(): void
    {
        $owner = $this->createTestUser();

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/oauth/clients/', $owner, ['name' => '']), 422);
        self::assertSame([], $this->listClients($owner));
    }

    public function testAnonymousRequestsAreRejected(): void
    {
        self::assertSame(401, $this->anonymousRequest('GET', '/api/oauth/clients/')->getStatusCode());
        self::assertSame(401, $this->anonymousRequest('POST', '/api/oauth/clients/', ['name' => 'CLI'])->getStatusCode());
    }

    public function testOwnerRevokesClient(): void
    {
        $owner = $this->createTestUser();
        $client = $this->createPersonalAccessClient($owner, 'Owner CLI');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/oauth/clients/' . $client['publicId'], $owner),
            200,
            'data',
        );

        self::assertSame('Client revoked successfully.', $data['data']['message']);
        self::assertSame([], $this->listClients($owner));
    }

    public function testAnotherUserCannotRevokeOrDiscoverClient(): void
    {
        $owner = $this->createTestUser();
        $attacker = $this->createTestUser();
        $client = $this->createPersonalAccessClient($owner, 'Owner CLI');

        $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/oauth/clients/' . $client['publicId'], $attacker),
            404,
            'error',
        );
        $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/oauth/clients/AAAAAAAAAAAAAAAAAAAAA', $attacker),
            404,
            'error',
        );

        self::assertSame([$client['publicId']], array_column($this->listClients($owner), 'publicId'));
    }

    public function testMalformedPublicIdIsRejected(): void
    {
        $owner = $this->createTestUser();

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/oauth/clients/not-valid!', $owner), 400, 'error');
    }

    public function testRevokedClientTokensStopAuthenticatingAndRefreshing(): void
    {
        $owner = $this->createTestUser();
        $client = $this->createPersonalAccessClient($owner, 'Owner CLI');
        $otherClient = $this->createPersonalAccessClient($owner, 'Owner scripts');
        $tokens = $this->issueTokens($owner, $client['uuid']);
        $otherTokens = $this->issueTokens($owner, $otherClient['uuid']);

        self::assertSame($owner->getId()->toString(), $this->authenticatedUserId($tokens->getAccessToken()));

        $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/oauth/clients/' . $client['publicId'], $owner),
            200,
        );

        try {
            $this->authenticatedUserId($tokens->getAccessToken());
            self::fail('An access token of a revoked client must not authenticate.');
        } catch (OAuthServerException $exception) {
            self::assertSame('access_denied', $exception->getErrorType());
        }

        $refreshToken = $tokens->getRefreshToken();
        self::assertIsString($refreshToken);
        try {
            $this->bus()->dispatch(new RefreshTokenCommand(
                $refreshToken,
                dpopJkt: self::DPOP_JKT,
                clientId: Uuid::fromString($client['uuid']),
            ));
            self::fail('A refresh token of a revoked client must not be redeemable.');
        } catch (HandlerFailedException) {
        }

        // Revocation is scoped to the client: the owner's other client keeps working.
        self::assertSame($owner->getId()->toString(), $this->authenticatedUserId($otherTokens->getAccessToken()));
    }

    /** @return array<string, mixed> */
    private function createPersonalAccessClient(User $user, string $name): array
    {
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/oauth/clients/', $user, ['name' => $name]),
            201,
            'data',
        );

        return $data['data'];
    }

    /** @return list<array<string, mixed>> */
    private function listClients(User $user): array
    {
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/oauth/clients/', $user), 200, 'data');

        return $data['data'];
    }

    private function issueTokens(User $user, string $clientUuid): TokenResponseDTO
    {
        $result = $this->bus()
            ->dispatch(new IssueTokenCommand(Uuid::fromString($clientUuid), $user->getId(), self::DPOP_JKT))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $result);

        return $result;
    }

    /** Validate a bearer token the way the production OAuth2 authenticator does. */
    private function authenticatedUserId(string $accessToken): string
    {
        $resourceServer = static::getContainer()->get(ResourceServer::class);
        self::assertInstanceOf(ResourceServer::class, $resourceServer);

        $request = $resourceServer->validateAuthenticatedRequest(
            new ServerRequest('GET', 'https://baander.app/api/auth/me', ['Authorization' => 'Bearer ' . $accessToken]),
        );
        $userId = $request->getAttribute('oauth_user_id');
        self::assertIsString($userId);

        return $userId;
    }

    private function bus(): MessageBusInterface
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
