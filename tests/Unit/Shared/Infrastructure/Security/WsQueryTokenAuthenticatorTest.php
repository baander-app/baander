<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Auth\Domain\Model\Email;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Security\WsQueryTokenAuthenticator;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;

final class WsQueryTokenAuthenticatorTest extends TestCase
{
    private WsQueryTokenAuthenticator $authenticator;
    private ResourceServer $resourceServer;
    private UserRepositoryInterface $userRepository;
    private HttpMessageFactoryInterface $psrHttpFactory;
    private Psr17Factory $psr17Factory;

    protected function setUp(): void
    {
        $this->resourceServer = $this->createStub(ResourceServer::class);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->psrHttpFactory = $this->createStub(HttpMessageFactoryInterface::class);
        $this->psr17Factory = new Psr17Factory();

        $this->authenticator = $this->createWsQueryTokenAuthenticatorFixture();
    }

    private function createWsQueryTokenAuthenticatorFixture(): WsQueryTokenAuthenticator
    {
        $fixture = new WsQueryTokenAuthenticator(
            $this->resourceServer,
            $this->userRepository,
            $this->psrHttpFactory,
        );
        return $fixture;
    }

    private function createSwooleRequest(array $get = [], string $requestUri = '/api/ws'): \Swoole\Http\Request
    {
        $request = new \Swoole\Http\Request();
        $request->get = $get;
        $request->server = ['request_uri' => $requestUri];

        return $request;
    }

    public function testAuthenticateReturnsNullWhenTokenParamIsMissing(): void
    {
        $swooleRequest = $this->createSwooleRequest();

        $result = $this->authenticator->authenticate($swooleRequest);

        $this->assertNull($result);
    }

    public function testAuthenticateReturnsNullWhenTokenParamIsEmpty(): void
    {
        $swooleRequest = $this->createSwooleRequest(['token' => '']);

        $result = $this->authenticator->authenticate($swooleRequest);

        $this->assertNull($result);
    }

    public function testAuthenticateReturnsNullWhenTokenIsInvalid(): void
    {
        $swooleRequest = $this->createSwooleRequest(['token' => 'invalid-token']);

        $psrRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws?token=invalid-token')
            ->withHeader('Authorization', 'Bearer invalid-token');

        $this->psrHttpFactory
            ->method('createRequest')
            ->willReturn($psrRequest);

        $this->resourceServer
            ->method('validateAuthenticatedRequest')
            ->willThrowException(OAuthServerException::accessDenied('Token validation failed'));

        $result = $this->authenticator->authenticate($swooleRequest);

        $this->assertNull($result);
    }

    public function testAuthenticateReturnsNullWhenTokenHasNoUserId(): void
    {
        $swooleRequest = $this->createSwooleRequest(['token' => 'valid-token']);

        $psrRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws?token=valid-token')
            ->withHeader('Authorization', 'Bearer valid-token');

        $validatedRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws')
            ->withAttribute('oauth_user_id', null);

        $this->psrHttpFactory
            ->method('createRequest')
            ->willReturn($psrRequest);

        $this->resourceServer
            ->method('validateAuthenticatedRequest')
            ->willReturn($validatedRequest);

        $result = $this->authenticator->authenticate($swooleRequest);

        $this->assertNull($result);
    }

    public function testAuthenticateReturnsNullWhenUserNotFound(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->authenticator = $this->createWsQueryTokenAuthenticatorFixture();

        $uuid = Uuid::fromString('00000000-0000-7000-8000-000000000099');
        $swooleRequest = $this->createSwooleRequest(['token' => 'valid-token']);

        $psrRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws?token=valid-token')
            ->withHeader('Authorization', 'Bearer valid-token');

        $validatedRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws')
            ->withAttribute('oauth_user_id', $uuid->toString());

        $this->psrHttpFactory
            ->method('createRequest')
            ->willReturn($psrRequest);

        $this->resourceServer
            ->method('validateAuthenticatedRequest')
            ->willReturn($validatedRequest);

        $this->userRepository
            ->expects($this->once())
            ->method('findByUuid')
            ->with($uuid)
            ->willReturn(null);

        $result = $this->authenticator->authenticate($swooleRequest);

        $this->assertNull($result);
    }

    public function testAuthenticateReturnsUserIdOnSuccess(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->authenticator = $this->createWsQueryTokenAuthenticatorFixture();

        $uuid = Uuid::fromString('00000000-0000-7000-8000-000000000001');
        $swooleRequest = $this->createSwooleRequest(['token' => 'valid-token']);

        $psrRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws?token=valid-token')
            ->withHeader('Authorization', 'Bearer valid-token');

        $validatedRequest = $this->psr17Factory->createServerRequest('GET', 'http://localhost/api/ws')
            ->withAttribute('oauth_user_id', $uuid->toString())
            ->withAttribute('oauth_client_id', 'test-client')
            ->withAttribute('oauth_scopes', ['read']);

        $this->psrHttpFactory
            ->method('createRequest')
            ->willReturn($psrRequest);

        $this->resourceServer
            ->method('validateAuthenticatedRequest')
            ->willReturn($validatedRequest);

        $user = User::reconstitute(new \App\Auth\Domain\Model\UserState(
            id: $uuid,
            publicId: new PublicId(),
            name: 'Test User',
            email: 'user@example.com',
            password: 'hashed-password',
            totpSecret: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->userRepository
            ->expects($this->once())
            ->method('findByUuid')
            ->with($uuid)
            ->willReturn($user);

        $result = $this->authenticator->authenticate($swooleRequest);

        $this->assertSame($uuid->toString(), $result);
    }
}
