<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\OAuth\OAuth2Authenticator;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Nyholm\Psr7\Request as Psr7Request;

final class OAuth2AuthenticatorTest extends TestCase
{
    private OAuth2Authenticator $authenticator;
    private ResourceServer&MockObject $resourceServer;
    private HttpMessageFactoryInterface&MockObject $psrFactory;

    protected function setUp(): void
    {
        $this->resourceServer = $this->createMock(ResourceServer::class);
        $this->psrFactory = $this->createMock(HttpMessageFactoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->authenticator = new OAuth2Authenticator(
            $this->resourceServer,
            $this->createMock(\App\Auth\Domain\Repository\UserRepositoryInterface::class),
            $this->psrFactory,
            $logger,
        );
    }

    public function testSupportsWithBearerHeader(): void
    {
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testDoesNotSupportWithoutBearerHeader(): void
    {
        $request = Request::create('/');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testDoesNotSupportWithNonBearerAuth(): void
    {
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Basic abc');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $result = $this->authenticator->onAuthenticationSuccess(
            Request::create('/'),
            $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class),
            'main',
        );

        $this->assertNull($result);
    }

    public function testOnAuthenticationFailureReturnsStructuredError(): void
    {
        $result = $this->authenticator->onAuthenticationFailure(
            Request::create('/'),
            new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
                'Invalid or expired token.',
                ['error_code' => 'AUTH_INVALID_TOKEN'],
            ),
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(401, $result->getStatusCode());

        $data = json_decode((string) $result->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data['error']);
        $this->assertArrayHasKey('code', $data['error']);
        $this->assertSame('AUTH_INVALID_TOKEN', $data['error']['code']);
        $this->assertSame('Invalid or expired token.', $data['error']['message']);
    }

    public function testOnAuthenticationFailureDefaultsToInvalidTokenCode(): void
    {
        $result = $this->authenticator->onAuthenticationFailure(
            Request::create('/'),
            new \Symfony\Component\Security\Core\Exception\BadCredentialsException('Invalid credentials'),
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(401, $result->getStatusCode());

        $data = json_decode((string) $result->getContent(), true);
        $this->assertSame('AUTH_INVALID_TOKEN', $data['error']['code']);
    }
}
