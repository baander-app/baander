<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\OAuth2Authenticator;
use App\Shared\Application\Http\BaanderHeader;
use App\Tests\Fixtures\Auth\AuthenticationFailureMessages;
use App\Tests\Functional\TestCase;
use League\OAuth2\Server\ResourceServer;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Logout ends the session on the server: the chain of the access token that
 * authenticated the request is revoked, so neither its access token nor its
 * refresh token work afterwards. `/api/oauth/revoke` revokes only the caller's
 * own tokens and answers a foreign token exactly like an unknown one.
 *
 * The test firewall swaps in TestAuthenticator, which never sees an access token,
 * so the `oauth_access_token_id` attribute that OAuth2Authenticator sets in
 * production is set here by a request listener. The production access token
 * authenticator is built from the container's services and driven directly to
 * show what a revoked token gets.
 */
final class LogoutTest extends TestCase
{
    private const string DPOP_JKT = 'logout-test-jkt';

    public function testLogoutRevokesTheChainOfTheAuthenticatedToken(): void
    {
        $user = $this->createTestUser();
        $client = $this->createSpaClient();
        $tokens = $this->refresh($client, $this->issueTokens($user, $client)->getRefreshToken());
        $chainId = $this->chainIdOf($tokens);

        self::assertSame(Response::HTTP_OK, $this->accessTokenStatus($tokens->getAccessToken()));
        self::assertGreaterThan(0, $this->liveTokenCounts($chainId)['access']);
        self::assertGreaterThan(0, $this->liveTokenCounts($chainId)['refresh']);

        $response = $this->logout($user, self::accessTokenId($tokens->getAccessToken()));

        $this->assertJsonResponse($response, Response::HTTP_OK, 'data');
        self::assertSame(['access' => 0, 'refresh' => 0], $this->liveTokenCounts($chainId));
        self::assertSame(['access' => 2, 'refresh' => 2], $this->tokenCounts($chainId), 'Both pairs of the chain are revoked, none is deleted.');
        self::assertSame('invalid_grant', $this->refreshError($client, $tokens->getRefreshToken()));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->accessTokenStatus($tokens->getAccessToken()));
    }

    public function testLogoutWithoutATokenAnswers401(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/logout');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), $response->getContent());
    }

    public function testRevokeAnswersAForeignTokenLikeAnUnknownOne(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $client = $this->createSpaClient();
        $tokens = $this->issueTokens($owner, $client);
        $chainId = $this->chainIdOf($tokens);
        $before = $this->liveTokenCounts($chainId);

        $foreign = $this->authenticatedRequest('POST', '/api/oauth/revoke', $other, [
            'token' => $tokens->getRefreshToken(),
            'tokenTypeHint' => 'refresh_token',
        ]);
        $unknown = $this->authenticatedRequest('POST', '/api/oauth/revoke', $other, [
            'token' => TokenId::generate()->toString(),
            'tokenTypeHint' => 'refresh_token',
        ]);

        self::assertSame(Response::HTTP_OK, $foreign->getStatusCode(), $foreign->getContent());
        self::assertSame($unknown->getStatusCode(), $foreign->getStatusCode());
        self::assertSame($unknown->getContent(), $foreign->getContent());
        self::assertSame($before, $this->liveTokenCounts($chainId), 'A foreign token revokes nothing.');
        self::assertSame(Response::HTTP_OK, $this->accessTokenStatus($tokens->getAccessToken()));
    }

    public function testRevokeRevokesTheCallersOwnChain(): void
    {
        $user = $this->createTestUser();
        $client = $this->createSpaClient();
        $tokens = $this->issueTokens($user, $client);
        $chainId = $this->chainIdOf($tokens);

        $response = $this->authenticatedRequest('POST', '/api/oauth/revoke', $user, [
            'token' => $tokens->getRefreshToken(),
            'tokenTypeHint' => 'refresh_token',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        self::assertSame(['access' => 0, 'refresh' => 0], $this->liveTokenCounts($chainId));
        self::assertSame('invalid_grant', $this->refreshError($client, $tokens->getRefreshToken()));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->accessTokenStatus($tokens->getAccessToken()));
    }

    /**
     * Sends the logout with the attribute the production authenticator sets for the
     * presenting token. The listener is removed again, so no later request carries it.
     */
    private function logout(User $user, string $accessTokenId): Response
    {
        $dispatcher = $this->service(EventDispatcherInterface::class);
        $listener = static function (RequestEvent $event) use ($accessTokenId): void {
            $event->getRequest()->attributes->set('oauth_access_token_id', $accessTokenId);
        };
        $dispatcher->addListener(KernelEvents::REQUEST, $listener, 1024);

        try {
            return $this->authenticatedRequest('POST', '/api/auth/logout', $user);
        } finally {
            $dispatcher->removeListener(KernelEvents::REQUEST, $listener);
        }
    }

    /** The response the production access token authenticator gives a DPoP-bound token presented under its scheme: 200 lets the request through. */
    private function accessTokenStatus(string $accessToken): int
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
        $request->headers->set('Authorization', 'DPoP ' . $accessToken);
        $request->headers->remove(BaanderHeader::TestUserId->value);
        self::assertTrue($authenticator->supports($request));

        try {
            $authenticator->authenticate($request)->getUser();
        } catch (AuthenticationException $exception) {
            return $authenticator->onAuthenticationFailure($request, $exception)->getStatusCode();
        }

        return Response::HTTP_OK;
    }

    /** The `jti` claim of the access token JWT, which is the token id the authenticator reports. */
    private static function accessTokenId(string $accessToken): string
    {
        $segments = explode('.', $accessToken);
        self::assertCount(3, $segments, 'The access token is a JWT.');
        $claims = json_decode(base64_decode(strtr($segments[1], '-_', '+/'), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsString($claims['jti'] ?? null);

        return $claims['jti'];
    }

    private function chainIdOf(TokenResponseDTO $tokens): string
    {
        $refreshToken = $this->service(RefreshTokenRepositoryInterface::class)
            ->findByTokenId(TokenId::fromString((string) $tokens->getRefreshToken()));
        self::assertNotNull($refreshToken);
        $chainId = $refreshToken->getChainId();
        self::assertNotNull($chainId, 'A pair issued with a refresh token belongs to a chain.');

        return $chainId->toString();
    }

    /** @return array{access: int, refresh: int} */
    private function liveTokenCounts(string $chainId): array
    {
        return $this->countTokens($chainId, 'AND revoked = FALSE');
    }

    /** @return array{access: int, refresh: int} */
    private function tokenCounts(string $chainId): array
    {
        return $this->countTokens($chainId, '');
    }

    /** @return array{access: int, refresh: int} */
    private function countTokens(string $chainId, string $condition): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            'access' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM oauth_access_tokens WHERE chain_id = :chainId ' . $condition,
                ['chainId' => $chainId],
            ),
            'refresh' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM oauth_refresh_tokens WHERE chain_id = :chainId ' . $condition,
                ['chainId' => $chainId],
            ),
        ];
    }

    /** The public client every user of this test signs in with. */
    private function createSpaClient(): Client
    {
        $client = Client::create('Logout test player', ['https://player.baander.app/callback']);
        $this->service(ClientRepositoryInterface::class)->saveClient($client);

        return $client;
    }

    private function issueTokens(User $user, Client $client): TokenResponseDTO
    {
        $result = $this->bus()
            ->dispatch(new IssueTokenCommand($client->getId(), $user->getId(), self::DPOP_JKT))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $result);

        return $result;
    }

    private function refresh(Client $client, ?string $refreshToken): TokenResponseDTO
    {
        $result = $this->bus()
            ->dispatch(new ExchangeRefreshTokenCommand($client->getPublicId()->toString(), null, $refreshToken, self::DPOP_JKT))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $result);

        return $result;
    }

    /** The OAuth error the token endpoint answers a refresh with. */
    private function refreshError(Client $client, ?string $refreshToken): string
    {
        try {
            $this->refresh($client, $refreshToken);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();
            self::assertInstanceOf(OAuthProtocolException::class, $cause);

            return $cause->error;
        }

        self::fail('The refresh token must not be redeemable.');
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
