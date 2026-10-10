<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\OAuth2Authenticator;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\AuthenticationFailureMessages;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
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
            AuthenticationFailureMessages::create(),
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

    public function testARefusedTokenIsReportedInTheAcceptLanguage(): void
    {
        $request = Request::create('/api/libraries', server: ['HTTP_ACCEPT_LANGUAGE' => 'th']);

        $result = $this->authenticator->onAuthenticationFailure($request, new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
            'Invalid or expired token.',
            ['error_code' => 'AUTH_INVALID_TOKEN'],
        ));

        $this->assertSame(
            ['error' => ['message' => 'โทเค็นไม่ถูกต้องหรือหมดอายุแล้ว', 'code' => 'AUTH_INVALID_TOKEN']],
            json_decode((string) $result->getContent(), true),
        );
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

    /** @return iterable<string, array{?string, ?string, bool}> stored fingerprint, request fingerprint, accepted */
    public static function fingerprintBindings(): iterable
    {
        yield 'bound, matching' => ['matching-fingerprint', 'matching-fingerprint', true];
        yield 'bound, different' => ['stored-fingerprint', 'different-fingerprint', false];
        yield 'bound, header missing' => ['stored-fingerprint', null, false];
        yield 'unbound, header missing' => [null, null, true];
        yield 'unbound, any header' => [null, 'any-fingerprint', true];
    }

    #[DataProvider('fingerprintBindings')]
    public function testAuthenticateEnforcesTheFingerprintOfBoundTokens(?string $stored, ?string $sent, bool $accepted): void
    {
        // League exposes the JWT jti, the access token's public identifier.
        $accessTokenId = TokenId::generate();
        $this->setupSuccessfulResourceServerValidation($accessTokenId->toString(), Uuid::generate()->toString());

        $tokenMetadataRepository = $this->createMock(TokenMetadataRepositoryInterface::class);
        $tokenMetadataRepository->expects($this->once())->method('findByTokenId')
            ->with($this->callback(static fn (TokenId $id): bool => $id->equals($accessTokenId)))
            ->willReturn(TokenMetadata::create(Uuid::generate(), clientFingerprint: $stored));

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');
        if ($sent !== null) {
            $request->headers->set('X-Baander-Client-Fingerprint', $sent);
        }

        if (!$accepted) {
            $this->expectException(CustomUserMessageAuthenticationException::class);
            $this->expectExceptionMessage('Invalid or expired token.');
        }

        $this->assertInstanceOf(SelfValidatingPassport::class, $this->createAuthenticator($tokenMetadataRepository)->authenticate($request));
    }

    public function testAuthenticateFailsClosedWhenTheBindingCannotBeLoaded(): void
    {
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), Uuid::generate()->toString());
        $tokenMetadataRepository = $this->createStub(TokenMetadataRepositoryInterface::class);
        $tokenMetadataRepository->method('findByTokenId')->willThrowException(new \RuntimeException('database unavailable'));

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->createAuthenticator($tokenMetadataRepository)->authenticate($request);
    }

    public function testATokenOfADisabledAccountDoesNotAuthenticate(): void
    {
        $user = \App\Auth\Domain\Model\User::register(
            new \App\Shared\Domain\Model\Email('disabled@baander.app'),
            'hashed-pw',
            'Disabled User',
        );
        $user->disable();
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $user->getId()->toString());

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');
        $passport = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $user)->authenticate($request);

        try {
            $passport->getUser();
            self::fail('A disabled account must not authenticate.');
        } catch (CustomUserMessageAuthenticationException $exception) {
            self::assertSame('Invalid or expired token.', $exception->getMessageKey());
            self::assertSame(401, $this->authenticator->onAuthenticationFailure($request, $exception)->getStatusCode());
        }
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

    private function createAuthenticator(
        TokenMetadataRepositoryInterface $tokenMetadataRepository,
        ?\App\Auth\Domain\Model\User $user = null,
    ): OAuth2Authenticator {
        $userRepository = $this->createStub(\App\Auth\Domain\Repository\UserRepositoryInterface::class);
        $userRepository->method('findByUuid')->willReturn(
            $user ?? \App\Auth\Domain\Model\User::register(
                new \App\Shared\Domain\Model\Email('test@baander.app'),
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
            AuthenticationFailureMessages::create(),
        );
    }
}
