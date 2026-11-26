<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\ArrayParameterType;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\ServerRequest;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
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
        self::assertArrayNotHasKey('secret', $first);

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

    /** A revoked client no longer counts, so revoking one makes room for another. */
    public function testAUserHoldsAtMostTwentyClients(): void
    {
        $owner = $this->createTestUser();
        $clients = array_map(fn (int $number): array => $this->createPersonalAccessClient($owner, 'CLI ' . $number), range(1, 20));

        $refused = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/oauth/clients/', $owner, ['name' => 'CLI 21']),
            409,
            'error',
        );

        self::assertSame(['reason' => 'personal_access_client_limit', 'limit' => 20], $refused['error']['details'] ?? null);
        self::assertCount(20, $this->listClients($owner));
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/oauth/clients/' . $clients[0]['publicId'], $owner), 200);
        $this->createPersonalAccessClient($owner, 'CLI 21');
        self::assertCount(20, $this->listClients($owner));
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
        $tokens = $this->issueTokens($owner, $client['publicId']);
        $otherTokens = $this->issueTokens($owner, $otherClient['publicId']);

        self::assertSame($owner->getPublicId()->toString(), $this->authenticatedUserId($tokens->getAccessToken()));

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
                clientId: PublicId::fromString($client['publicId']),
            ));
            self::fail('A refresh token of a revoked client must not be redeemable.');
        } catch (HandlerFailedException) {
        }

        // Revocation is scoped to the client: the owner's other client keeps working.
        self::assertSame($owner->getPublicId()->toString(), $this->authenticatedUserId($otherTokens->getAccessToken()));
    }

    public function testThePurgeDeletesRevokedClientsWithTheirTokensAndKeepsActiveOnes(): void
    {
        $owner = $this->createTestUser();
        $revoked = $this->createPersonalAccessClient($owner, 'Old CLI');
        $alsoRevoked = $this->createPersonalAccessClient($owner, 'Old scripts');
        $active = $this->createPersonalAccessClient($owner, 'Current CLI');
        $tokens = $this->issueTokens($owner, $revoked['publicId']);
        $accessTokenIds = $this->accessTokenIdsOf($this->clientId($revoked['publicId']));
        self::assertNotSame([], $accessTokenIds);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/oauth/clients/' . $revoked['publicId'], $owner), 200);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/oauth/clients/' . $alsoRevoked['publicId'], $owner), 200);

        $purge = new CommandTester((new Application($this->client->getKernel()))->find('app:oauth:client:purge-revoked'));

        self::assertSame(Command::SUCCESS, $purge->execute([]), $purge->getDisplay());
        self::assertStringContainsString('Deleted 2 revoked personal access client(s).', preg_replace('/\s+/', ' ', $purge->getDisplay()) ?? '');
        self::assertSame([$active['publicId']], $this->storedClientPublicIds([$revoked['publicId'], $alsoRevoked['publicId'], $active['publicId']]));
        self::assertSame([$active['publicId']], array_column($this->listClients($owner), 'publicId'));
        $connection = $this->entityManager->getConnection();
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE id IN (?)',
            [$accessTokenIds],
            [ArrayParameterType::STRING],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT count(*) FROM oauth_refresh_tokens WHERE access_token_id IN (?)',
            [$accessTokenIds],
            [ArrayParameterType::STRING],
        ));

        try {
            $this->authenticatedUserId($tokens->getAccessToken());
            self::fail('An access token of a purged client must not authenticate.');
        } catch (OAuthServerException $exception) {
            self::assertSame('access_denied', $exception->getErrorType());
        }
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

    private function clientId(string $publicId): Uuid
    {
        $client = static::getContainer()->get(ClientRepositoryInterface::class)
            ->findClientByPublicId(PublicId::fromString($publicId));
        self::assertNotNull($client);

        return $client->getId();
    }

    /** @return list<string> */
    private function accessTokenIdsOf(Uuid $clientId): array
    {
        /** @var list<string> */
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM oauth_access_tokens WHERE client_id = ?',
            [$clientId->toString()],
        );
    }

    /**
     * @param list<string> $publicIds
     *
     * @return list<string> those still stored, in the given order
     */
    private function storedClientPublicIds(array $publicIds): array
    {
        $stored = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT public_id FROM oauth_clients WHERE public_id IN (?)',
            [$publicIds],
            [ArrayParameterType::STRING],
        );

        return array_values(array_intersect($publicIds, $stored));
    }

    private function issueTokens(User $user, string $clientPublicId): TokenResponseDTO
    {
        $result = $this->bus()
            ->dispatch(new IssueTokenCommand($this->clientId($clientPublicId), $user->getId(), self::DPOP_JKT, 0, SignInGrant::AuthorizationCode))
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
