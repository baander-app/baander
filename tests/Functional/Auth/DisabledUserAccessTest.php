<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\OAuth2Authenticator;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Security\WsQueryTokenAuthenticator;
use App\Tests\Fixtures\Auth\AuthenticationFailureMessages;
use App\Tests\Functional\TestCase;
use League\OAuth2\Server\ResourceServer;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Disabling an account ends its sessions at once: bearer requests, WebSocket
 * handshakes and refreshes with tokens issued before the disable are refused.
 *
 * The test firewall swaps in TestAuthenticator, so the production authenticators
 * are built from the container's services and driven directly.
 */
final class DisabledUserAccessTest extends TestCase
{
    private const string DPOP_JKT = 'disabled-user-access-test-jkt';

    /** @var array<string, string> client UUIDs by public ID */
    private array $clientUuids = [];

    public function testDisablingEndsEverySessionWithinOneRequest(): void
    {
        $user = $this->createTestUser();
        $clientPublicId = $this->createPersonalAccessClient($user);
        $tokens = $this->refresh($clientPublicId, $this->issueTokens($user, $clientPublicId)->getRefreshToken());

        self::assertSame(Response::HTTP_OK, $this->bearerStatus($tokens->getAccessToken()));
        self::assertSame($user->getId()->toString(), $this->webSocketUserId($tokens->getAccessToken()));

        $this->bus()->dispatch(new DisableUserCommand($user->getEmail()));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->bearerStatus($tokens->getAccessToken()));
        self::assertNull($this->webSocketUserId($tokens->getAccessToken()));
        self::assertSame('invalid_grant', $this->refreshError($clientPublicId, $tokens->getRefreshToken()));
        self::assertSame(['access' => 0, 'refresh' => 0], $this->activeTokenCounts($user));
    }

    public function testDisablingADisabledUserSucceedsWithoutChange(): void
    {
        $user = $this->createTestUser();
        $this->bus()->dispatch(new DisableUserCommand($user->getEmail()));
        $disabledAt = $this->storedUser($user)->getUpdatedAt();

        $this->bus()->dispatch(new DisableUserCommand($user->getId()->toString()));

        $stored = $this->storedUser($user);
        self::assertTrue($stored->isDisabled());
        self::assertEquals($disabledAt, $stored->getUpdatedAt());
    }

    public function testEnablingDoesNotRestoreRevokedTokens(): void
    {
        $user = $this->createTestUser();
        $clientPublicId = $this->createPersonalAccessClient($user);
        $tokens = $this->issueTokens($user, $clientPublicId);

        $this->bus()->dispatch(new DisableUserCommand($user->getEmail()));
        $this->bus()->dispatch(new EnableUserCommand($user->getEmail()));

        self::assertFalse($this->storedUser($user)->isDisabled());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->bearerStatus($tokens->getAccessToken()));
        self::assertSame('invalid_grant', $this->refreshError($clientPublicId, $tokens->getRefreshToken()));

        // Signing in again issues a working pair.
        $fresh = $this->issueTokens($user, $clientPublicId);
        self::assertSame(Response::HTTP_OK, $this->bearerStatus($fresh->getAccessToken()));
    }

    public function testAnAuthorizationCodeIssuedBeforeADisableCannotBeExchanged(): void
    {
        // RFC 7636 appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        $redirectUri = 'https://player.baander.app/callback';
        $user = $this->createTestUser();
        $client = Client::create('Disabled user player', [$redirectUri]);
        $this->service(ClientRepositoryInterface::class)->saveClient($client);
        $clientId = $client->getPublicId()->toString();

        $authorization = $this->bus()
            ->dispatch(new CreateAuthorizationCodeCommand($user->getId(), 'code', $clientId, $redirectUri, $challenge, 'S256'))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(AuthorizationResponseDTO::class, $authorization);
        self::assertIsString($authorization->code);

        $this->bus()->dispatch(new DisableUserCommand($user->getEmail()));
        $this->entityManager->clear();

        try {
            $this->bus()->dispatch(new ExchangeAuthorizationCodeCommand($clientId, null, $authorization->code, $redirectUri, $verifier, self::DPOP_JKT));
            self::fail('The authorization code of a disabled user must not be exchangeable.');
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();
            self::assertInstanceOf(OAuthProtocolException::class, $cause);
            self::assertSame('invalid_grant', $cause->error);
        }
        self::assertSame(['access' => 0, 'refresh' => 0], $this->activeTokenCounts($user));
    }

    /** The response the production bearer authenticator gives: 200 lets the request through. */
    private function bearerStatus(string $accessToken): int
    {
        $authenticator = new OAuth2Authenticator(
            $this->service(ResourceServer::class),
            $this->userRepository,
            $this->service(HttpMessageFactoryInterface::class),
            new NullLogger(),
            $this->service(TokenMetadataRepositoryInterface::class),
            AuthenticationFailureMessages::create(),
        );

        $request = Request::create('https://baander.app/api/auth/me');
        $request->headers->set('Authorization', 'Bearer ' . $accessToken);
        self::assertTrue($authenticator->supports($request));

        try {
            $authenticator->authenticate($request)->getUser();
        } catch (AuthenticationException $exception) {
            return $authenticator->onAuthenticationFailure($request, $exception)->getStatusCode();
        }

        return Response::HTTP_OK;
    }

    private function webSocketUserId(string $accessToken): ?string
    {
        $authenticator = new WsQueryTokenAuthenticator(
            $this->service(ResourceServer::class),
            $this->userRepository,
            $this->service(HttpMessageFactoryInterface::class),
        );

        $request = new \Swoole\Http\Request();
        $request->get = ['token' => $accessToken];
        $request->server = ['request_uri' => '/api/ws'];

        return $authenticator->authenticate($request);
    }

    private function refresh(string $clientPublicId, ?string $refreshToken): TokenResponseDTO
    {
        $result = $this->bus()
            ->dispatch(new ExchangeRefreshTokenCommand($clientPublicId, null, $refreshToken, self::DPOP_JKT))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $result);

        return $result;
    }

    /** The OAuth error the token endpoint answers a refresh with. */
    private function refreshError(string $clientPublicId, ?string $refreshToken): string
    {
        try {
            $this->refresh($clientPublicId, $refreshToken);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();
            self::assertInstanceOf(OAuthProtocolException::class, $cause);

            return $cause->error;
        }

        self::fail('The refresh token must not be redeemable.');
    }

    /** @return array{access: int, refresh: int} */
    private function activeTokenCounts(User $user): array
    {
        $connection = $this->entityManager->getConnection();
        $userId = $user->getId()->toString();

        return [
            'access' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id = :userId AND revoked = FALSE',
                ['userId' => $userId],
            ),
            'refresh' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM oauth_refresh_tokens r JOIN oauth_access_tokens a ON a.id = r.access_token_id
                  WHERE a.user_id = :userId AND r.revoked = FALSE',
                ['userId' => $userId],
            ),
        ];
    }

    /** Creates a public personal access client of the user and returns its public ID. */
    private function createPersonalAccessClient(User $user): string
    {
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/oauth/clients/', $user, ['name' => 'Member CLI']),
            201,
            'data',
        );
        $this->clientUuids[$data['data']['publicId']] = $data['data']['uuid'];

        return $data['data']['publicId'];
    }

    private function issueTokens(User $user, string $clientPublicId): TokenResponseDTO
    {
        $result = $this->bus()
            ->dispatch(new IssueTokenCommand(Uuid::fromString($this->clientUuids[$clientPublicId]), $user->getId(), self::DPOP_JKT))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $result);

        return $result;
    }

    private function storedUser(User $user): User
    {
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function bus(): MessageBusInterface
    {
        return $this->service(MessageBusInterface::class);
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
