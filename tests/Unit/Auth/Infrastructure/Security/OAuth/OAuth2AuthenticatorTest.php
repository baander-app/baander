<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\OAuth2Authenticator;
use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Nyholm\Psr7\ServerRequest as Psr7Request;

final class OAuth2AuthenticatorTest extends TestCase
{
    private OAuth2Authenticator $authenticator;
    private ResourceServer&Stub $resourceServer;
    private HttpMessageFactoryInterface&Stub $psrFactory;

    protected function setUp(): void
    {
        $this->resourceServer = $this->createStub(ResourceServer::class);
        $this->psrFactory = $this->createStub(HttpMessageFactoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $tokenMetadataRepository = $this->createStub(TokenMetadataRepositoryInterface::class);
        $this->authenticator = new OAuth2Authenticator(
            $this->resourceServer,
            $this->createStub(\App\Auth\Domain\Repository\UserRepositoryInterface::class),
            $this->psrFactory,
            $logger,
            $tokenMetadataRepository,
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
            $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class),
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

    public function testAuthenticateAllowsRequestWhenFingerprintMatches(): void
    {
        $userId = Uuid::generate()->toString();
        $accessTokenId = Uuid::generate()->toString();

        $this->setupSuccessfulResourceServerValidation($accessTokenId, $userId);

        $tokenMetadataRepository = $this->createConfiguredStub(TokenMetadataRepositoryInterface::class, [
            'findByTokenId' => TokenMetadata::create(
                Uuid::fromString($accessTokenId),
                clientFingerprint: 'matching-fingerprint',
            ),
        ]);

        $authenticator = $this->createAuthenticator($tokenMetadataRepository);

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');
        $request->headers->set('X-Client-Fingerprint', 'matching-fingerprint');

        $passport = $authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
    }

    public function testAuthenticateRejectsRequestWhenFingerprintMismatches(): void
    {
        $userId = Uuid::generate()->toString();
        $accessTokenId = Uuid::generate()->toString();

        $this->setupSuccessfulResourceServerValidation($accessTokenId, $userId);

        $tokenMetadataRepository = $this->createConfiguredStub(TokenMetadataRepositoryInterface::class, [
            'findByTokenId' => TokenMetadata::create(
                Uuid::fromString($accessTokenId),
                clientFingerprint: 'stored-fingerprint',
            ),
        ]);

        $authenticator = $this->createAuthenticator($tokenMetadataRepository);

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');
        $request->headers->set('X-Client-Fingerprint', 'different-fingerprint');

        $this->expectException(\Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired token.');

        $authenticator->authenticate($request);
    }

    private function setupSuccessfulResourceServerValidation(string $accessTokenId, string $userId): void
    {
        $psrRequest = new Psr7Request('GET', '/');
        $psrRequest = $psrRequest
            ->withAttribute('oauth_access_token_id', $accessTokenId)
            ->withAttribute('oauth_user_id', $userId)
            ->withAttribute('oauth_client_id', 'client-uuid')
            ->withAttribute('oauth_scopes', ['profile']);

        $this->psrFactory->method('createRequest')->willReturn($psrRequest);
        $this->resourceServer->method('validateAuthenticatedRequest')->willReturn($psrRequest);
    }

    private function createAuthenticator(TokenMetadataRepositoryInterface $tokenMetadataRepository): OAuth2Authenticator
    {
        $userRepository = $this->createStub(\App\Auth\Domain\Repository\UserRepositoryInterface::class);
        $userRepository->method('findByUuid')->willReturn(
            \App\Auth\Domain\Model\User::register(
                new \App\Shared\Domain\Model\Email('test@example.com'),
                'hashed-pw',
                'Test User',
            ),
        );

        return new OAuth2Authenticator(
            $this->resourceServer,
            $userRepository,
            $this->psrFactory,
            $this->createStub(LoggerInterface::class),
            $tokenMetadataRepository,
        );
    }
}
