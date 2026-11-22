<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenIssuance;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\OAuth2Authenticator;
use App\Auth\Infrastructure\Security\SecurityUser;
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
    private const string SPA_CLIENT_ID = 'baander_web_spa_00001';

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
            $this->createStub(ClientRepositoryInterface::class),
            $this->createStub(AccessTokenRepositoryInterface::class),
            self::SPA_CLIENT_ID,
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
        $this->setupSuccessfulResourceServerValidation($accessTokenId->toString(), (new \App\Shared\Domain\Model\PublicId())->toString());

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

    /** The approval endpoints read the epoch the token was issued under from the request. */
    public function testACurrentTokenSetsItsCredentialEpochOnTheRequest(): void
    {
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), (new \App\Shared\Domain\Model\PublicId())->toString());
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');

        $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), credentialEpoch: 5)->authenticate($request);

        self::assertSame(5, $request->attributes->get(AccessTokenAttributes::CREDENTIAL_EPOCH));
    }

    /** A token issued before the user's latest password change is refused, even when nothing revoked it. */
    public function testATokenBehindTheUsersCredentialEpochDoesNotAuthenticate(): void
    {
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), (new \App\Shared\Domain\Model\PublicId())->toString());
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired token.');

        $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), credentialEpoch: null)->authenticate($request);
    }

    public function testATokenOfADisabledAccountDoesNotAuthenticate(): void
    {
        $user = \App\Auth\Domain\Model\User::register(
            new \App\Shared\Domain\Model\Email('disabled@baander.app'),
            'hashed-pw',
            'Disabled User',
        );
        $user->disable();
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $user->getPublicId()->toString());

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

    /** @return iterable<string, array{bool}> */
    public static function addressStates(): iterable
    {
        yield 'unverified address' => [false];
        yield 'verified address' => [true];
    }

    #[DataProvider('addressStates')]
    public function testTheRequestCarriesWhetherTheTokenUsersAddressIsVerified(bool $verified): void
    {
        $user = $this->user('registrant@baander.app');
        if ($verified) {
            $user->verifyEmail();
        }
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $user->getPublicId()->toString());
        $request = Request::create('/api/playlists/');
        $request->headers->set('Authorization', 'Bearer token123');

        $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $user)->authenticate($request)->getUser();

        self::assertSame($verified, $request->attributes->get(AccessTokenAttributes::EMAIL_VERIFIED));
    }

    /** A DPoP-bound token presented under the Bearer scheme is refused like any other invalid token, before any user is loaded. */
    public function testABoundTokenUnderTheBearerSchemeIsRefused(): void
    {
        $user = $this->user('bound@baander.app');
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $user->getPublicId()->toString(), 'bound-key-thumbprint');
        $userRepository = $this->createMock(\App\Auth\Domain\Repository\UserRepositoryInterface::class);
        $userRepository->expects($this->never())->method('findByPublicId');
        $authenticator = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), userRepository: $userRepository);

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');

        try {
            $authenticator->authenticate($request);
            self::fail('A DPoP-bound token must not authenticate under the Bearer scheme.');
        } catch (CustomUserMessageAuthenticationException $exception) {
            self::assertSame('Invalid or expired token.', $exception->getMessageKey());
            $response = $authenticator->onAuthenticationFailure($request, $exception);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame(
                ['error' => ['message' => 'Invalid or expired token.', 'code' => 'AUTH_INVALID_TOKEN']],
                json_decode((string) $response->getContent(), true),
            );
        }
    }

    public function testABoundTokenUnderTheDpopSchemeAuthenticatesItsUser(): void
    {
        $user = $this->user('bound@baander.app');
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $user->getPublicId()->toString(), 'bound-key-thumbprint');

        $request = Request::create('/');
        $request->headers->set('Authorization', 'DPoP token123');
        $passport = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $user)->authenticate($request);

        $securityUser = $passport->getUser();
        self::assertInstanceOf(SecurityUser::class, $securityUser);
        self::assertSame($user->getId()->toString(), $securityUser->getId());
    }

    public function testAnUnboundTokenUnderTheBearerSchemeAuthenticatesItsUser(): void
    {
        $user = $this->user('unbound@baander.app');
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $user->getPublicId()->toString());

        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token123');
        $passport = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $user)->authenticate($request);

        $securityUser = $passport->getUser();
        self::assertInstanceOf(SecurityUser::class, $securityUser);
        self::assertSame($user->getId()->toString(), $securityUser->getId());
    }

    /** @return iterable<string, array{SignInGrant}> */
    public static function firstPartySignIns(): iterable
    {
        yield 'password sign-in' => [SignInGrant::Password];
        yield 'passkey sign-in' => [SignInGrant::Passkey];
    }

    #[DataProvider('firstPartySignIns')]
    public function testASignInOfTheFirstPartyClientCarriesTheFirstPartyMarkerNoScopesAndTheUsersRoles(SignInGrant $grant): void
    {
        $admin = $this->admin();
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $admin->getPublicId()->toString(), 'jkt', self::SPA_CLIENT_ID, []);

        $request = $this->dpopRequest();
        $user = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $admin, client: $this->firstPartyClient(), grant: $grant)->authenticate($request)->getUser();

        self::assertTrue($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame([], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame(self::SPA_CLIENT_ID, $request->attributes->get(AccessTokenAttributes::CLIENT_ID));
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    /** @return iterable<string, array{SignInGrant}> */
    public static function delegatedGrants(): iterable
    {
        yield 'authorization code' => [SignInGrant::AuthorizationCode];
        yield 'device code' => [SignInGrant::DeviceCode];
    }

    /**
     * Issuance never pairs the first-party client with a delegated grant, so such a token was
     * minted around it, for example through a pushed request naming the first-party client. It
     * keeps neither the first-party authority of its client nor the scopes it was granted.
     */
    #[DataProvider('delegatedGrants')]
    public function testATokenOfTheFirstPartyClientFromADelegatedGrantIsDelegatedWithoutScopes(SignInGrant $grant): void
    {
        $admin = $this->admin();
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $admin->getPublicId()->toString(), 'jkt', self::SPA_CLIENT_ID, ['library', 'playlist', 'profile']);

        $request = $this->dpopRequest();
        $user = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $admin, client: $this->firstPartyClient(), grant: $grant)->authenticate($request)->getUser();

        self::assertFalse($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame([], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testAPersonalAccessTokenOfAnAdminIsDelegatedWithItsScopesAndNoAdminRole(): void
    {
        $admin = $this->admin();
        $client = Client::createPersonalAccess('Scripts', $admin->getId());
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $admin->getPublicId()->toString(), 'jkt', $client->getPublicId()->toString(), ['library', 'playlist']);

        $request = $this->dpopRequest();
        $user = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $admin, client: $client)->authenticate($request)->getUser();

        self::assertFalse($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame(['library', 'playlist'], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame($client->getPublicId()->toString(), $request->attributes->get(AccessTokenAttributes::CLIENT_ID));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testAFirstPartyTypedClientUnderAnotherPublicIdIsDelegated(): void
    {
        $admin = $this->admin();
        $client = Client::create('Look-alike', [], firstParty: true, passwordClient: true);
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $admin->getPublicId()->toString(), 'jkt', $client->getPublicId()->toString(), []);

        $request = $this->dpopRequest();
        $user = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $admin, client: $client, grant: SignInGrant::Password)->authenticate($request)->getUser();

        self::assertFalse($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame([], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testTheSpaPublicIdOnAClientThatIsNotFirstPartyIsDelegated(): void
    {
        $admin = $this->admin();
        $client = Client::create('Impostor', [], publicId: \App\Shared\Domain\Model\PublicId::fromString(self::SPA_CLIENT_ID));
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), $admin->getPublicId()->toString(), 'jkt', self::SPA_CLIENT_ID, ['library']);

        $request = $this->dpopRequest();
        $user = $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), $admin, client: $client, grant: SignInGrant::Password)->authenticate($request)->getUser();

        self::assertFalse($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame(['library'], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    private function firstPartyClient(): Client
    {
        return Client::create('Baander', [], firstParty: true, passwordClient: true, publicId: \App\Shared\Domain\Model\PublicId::fromString(self::SPA_CLIENT_ID));
    }

    private function admin(): \App\Auth\Domain\Model\User
    {
        return \App\Auth\Domain\Model\User::createByOperator(new \App\Shared\Domain\Model\Email('admin-' . bin2hex(random_bytes(4)) . '@baander.app'), 'hashed-pw', 'Admin', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    private function dpopRequest(): Request
    {
        $request = Request::create('/api/auth/me');
        $request->headers->set('Authorization', 'DPoP token123');

        return $request;
    }

    private function user(string $email): \App\Auth\Domain\Model\User
    {
        return \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email($email), 'hashed-pw', 'Test User');
    }


    public function testAnAlreadyIssuedUuidSubjectIsRejectedWithoutLookingUpAnInternalIdentity(): void
    {
        $this->setupSuccessfulResourceServerValidation(TokenId::generate()->toString(), Uuid::generate()->toString());
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findByUuid');
        $users->expects(self::never())->method('findByPublicId');
        $request = Request::create('/api/auth/me');
        $request->headers->set('Authorization', 'Bearer existing-token');

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->createAuthenticator($this->createStub(TokenMetadataRepositoryInterface::class), userRepository: $users)->authenticate($request);
    }

    /** @param list<string> $scopes */
    private function setupSuccessfulResourceServerValidation(
        string $accessTokenId,
        string $userId,
        ?string $dpopJkt = null,
        string $clientId = 'V1StGXR8_Z5jdHi6B-myT',
        array $scopes = ['profile'],
    ): void {
        $psrRequest = new Psr7Request('GET', '/');
        $psrRequest = $psrRequest
            ->withAttribute('oauth_access_token_id', $accessTokenId)
            ->withAttribute('oauth_user_id', $userId)
            ->withAttribute('oauth_client_id', $clientId)
            ->withAttribute('oauth_scopes', $scopes);
        if ($dpopJkt !== null) {
            $psrRequest = $psrRequest->withAttribute('oauth_dpop_jkt', $dpopJkt);
        }

        $this->psrFactory->method('createRequest')->willReturn($psrRequest);
        $this->resourceServer->method('validateAuthenticatedRequest')->willReturn($psrRequest);
    }

    private function createAuthenticator(
        TokenMetadataRepositoryInterface $tokenMetadataRepository,
        ?\App\Auth\Domain\Model\User $user = null,
        ?\App\Auth\Domain\Repository\UserRepositoryInterface $userRepository = null,
        ?Client $client = null,
        ?int $credentialEpoch = 5,
        SignInGrant $grant = SignInGrant::AuthorizationCode,
    ): OAuth2Authenticator {
        if ($userRepository === null) {
            $userRepository = $this->createStub(\App\Auth\Domain\Repository\UserRepositoryInterface::class);
            $userRepository->method('findByPublicId')->willReturn($user ?? $this->user('test@baander.app'));
        }
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('isFirstPartyClient')->willReturn($client?->getType() === ClientType::FirstParty);
        // A null epoch stands for a token whose user changed the password after it was issued.
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('currentIssuance')->willReturn($credentialEpoch === null ? null : new TokenIssuance($grant, $credentialEpoch));

        return new OAuth2Authenticator(
            $this->resourceServer,
            $userRepository,
            $this->psrFactory,
            $this->createStub(LoggerInterface::class),
            $tokenMetadataRepository,
            AuthenticationFailureMessages::create(),
            $clients,
            $accessTokens,
            self::SPA_CLIENT_ID,
        );
    }
}
