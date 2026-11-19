<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\CommandHandler\OAuth\CreateAuthorizationCodeHandler;
use App\Auth\Application\CommandHandler\OAuth\ExchangeAuthorizationCodeHandler;
use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Query\OAuth\GetAuthorizationRequestQuery;
use App\Auth\Application\QueryHandler\OAuth\GetAuthorizationRequestHandler;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequestState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\PushedAuthorizationRequestRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The authorization code grant from a pushed authorization request (RFC 9126) to the token
 * exchange, with mandatory S256 PKCE (RFC 7636) and the code bound to the pushed request's
 * DPoP key (RFC 9449 section 10).
 */
final class AuthorizationCodeGrantTest extends TestCase
{
    use IssuesTokenPairs;

    private const string REDIRECT = 'https://app.baander.app/callback';
    private const string JKT = 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs';
    private const string OTHER_JKT = '0ZcOCORZNYy-DWpqq30jZyJGHTN0d2HglBV3uiguA4I';
    // RFC 7636 appendix B.
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @var array<string, AuthCode> */
    private array $codes = [];
    /** @var array<string, true> */
    private array $redeemed = [];
    /** @var array<string, PushedAuthorizationRequest> */
    private array $pushed = [];
    private User $user;
    private Client $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        $this->user = User::register(new Email('listener@baander.app'), 'hashed', 'Listener');
        $this->client = $this->register(Client::create('Third-party player', [self::REDIRECT, 'https://app.baander.app/other']));
    }

    public function testApprovalIssuesACodeBoundToThePushedChallengeAndKey(): void
    {
        $request = $this->pushRequest();

        $result = $this->decide($request);

        self::assertSame(self::REDIRECT, $result->redirectUri);
        self::assertSame('pushed-state', $result->state);
        self::assertIsString($result->code);
        $code = $this->codes[$result->code];
        self::assertSame(self::CHALLENGE, $code->getCodeChallenge());
        self::assertSame(self::REDIRECT, $code->getRedirectUri());
        self::assertSame(self::JKT, $code->getDpopJkt());
        self::assertSame(['library'], $code->getScopeIdentifiers());
        $lifetime = $code->getExpiresAt()?->getTimestamp() - $code->getCreatedAt()->getTimestamp();
        self::assertSame(60, $lifetime);
        self::assertTrue($this->pushed[$request->getRequestId()->toString()]->isConsumed(), 'The decision uses the request up.');
    }

    public function testTheConsentPageSeesThePushedClientAndScopesWithoutACodeBeingIssued(): void
    {
        $request = $this->pushRequest();

        $details = $this->describe($request);

        self::assertSame($this->client->getPublicId()->toString(), $details->clientId);
        self::assertSame('Third-party player', $details->clientName);
        self::assertSame('public', $details->clientType);
        self::assertSame(['library'], $details->scopes);
        self::assertSame(self::REDIRECT, $details->redirectUri);
        self::assertTrue($details->consentRequired);
        self::assertSame([], $this->codes);
        self::assertFalse($this->pushed[$request->getRequestId()->toString()]->isConsumed(), 'Looking a request up does not use it up.');
        self::assertSame([], $this->describe($this->pushRequest(scopes: []))->scopes, 'No allowed scope grants no scope.');
    }

    public function testLookingARequestUpTwiceThenApprovingIssuesOneCode(): void
    {
        $request = $this->pushRequest();

        $this->describe($request);
        $this->describe($request);
        $this->decide($request);

        self::assertCount(1, $this->codes);
    }

    public function testASecondApprovalOfTheSameRequestIsRejectedWithoutASecondCode(): void
    {
        $request = $this->pushRequest();
        $this->decide($request);

        $this->assertUnusableRequest(fn () => $this->decide($request));
        $this->assertUnusableRequest(fn () => $this->describe($request));
        self::assertCount(1, $this->codes);
    }

    public function testAnExpiredRequestIsRejectedWithoutARedirect(): void
    {
        $request = $this->pushRequest();
        $this->clock->sleep(301);

        $this->assertUnusableRequest(fn () => $this->describe($request));
        $this->assertUnusableRequest(fn () => $this->decide($request));
        self::assertSame([], $this->codes);
    }

    public function testAnotherClientsIdWithTheRequestIsRejected(): void
    {
        $request = $this->pushRequest();
        $other = $this->register(Client::create('Other app', [self::REDIRECT]));

        $this->assertUnusableRequest(fn () => $this->describe($request, clientId: $other->getPublicId()->toString()));
        $this->assertUnusableRequest(fn () => $this->decide($request, clientId: $other->getPublicId()->toString()));
        self::assertFalse($this->pushed[$request->getRequestId()->toString()]->isConsumed());
    }

    /** @return iterable<string, array{string}> */
    public static function unusableRequestUris(): iterable
    {
        yield 'unknown request' => [PushedAuthorizationRequest::REQUEST_URI_PREFIX . TokenId::generate()->toString()];
        yield 'another prefix' => ['urn:example:' . TokenId::generate()->toString()];
        yield 'no identifier' => [PushedAuthorizationRequest::REQUEST_URI_PREFIX];
        yield 'an https URI' => ['https://app.baander.app/request/1'];
    }

    #[DataProvider('unusableRequestUris')]
    public function testAnUnknownOrMalformedRequestUriIsRejected(string $requestUri): void
    {
        $this->assertUnusableRequest(fn () => (new GetAuthorizationRequestHandler($this->validator()))(
            new GetAuthorizationRequestQuery($this->user->getId(), $this->client->getPublicId()->toString(), $requestUri),
        ));
    }

    public function testAnUnknownClientIsNotRedirected(): void
    {
        $exception = $this->assertProtocolError('invalid_client', fn () => $this->describe($this->pushRequest(), clientId: 'unknown_client_000001'), 401);

        self::assertNull($exception->redirectUri);
    }

    public function testDenialUsesTheRequestUpAndAnswersAccessDeniedWithThePushedState(): void
    {
        $request = $this->pushRequest();

        $answer = $this->decide($request, approved: false);

        self::assertNull($answer->code);
        self::assertSame('access_denied', $answer->error);
        self::assertSame(self::REDIRECT, $answer->redirectUri);
        self::assertSame('pushed-state', $answer->state);
        self::assertSame([], $this->codes);
        $this->assertUnusableRequest(fn () => $this->decide($request));
    }

    public function testADisabledUserIsSentBackWithAccessDeniedAndTheRequestStaysPending(): void
    {
        $request = $this->pushRequest();
        $this->user->disable();

        $exception = $this->assertProtocolError('access_denied', fn () => $this->decide($request));

        self::assertSame(self::REDIRECT, $exception->redirectUri);
        self::assertSame('pushed-state', $exception->state);
        self::assertFalse($this->pushed[$request->getRequestId()->toString()]->isConsumed());
        self::assertSame([], $this->codes);
    }

    public function testAPersonalAccessClientServesOnlyItsOwnerWithoutConsent(): void
    {
        $own = $this->register(Client::createPersonalAccess('CLI', $this->user->getId()));
        $foreign = $this->register(Client::createPersonalAccess('Other CLI', Uuid::generate()));

        self::assertFalse($this->describe($this->pushRequest($own, 'http://localhost'), clientId: $own->getPublicId()->toString())->consentRequired);

        $foreignRequest = $this->pushRequest($foreign, 'http://localhost');
        $exception = $this->assertProtocolError('unauthorized_client', fn () => $this->decide($foreignRequest, clientId: $foreign->getPublicId()->toString()));
        self::assertSame('http://localhost', $exception->redirectUri);
        self::assertSame('pushed-state', $exception->state);
        self::assertFalse($this->pushed[$foreignRequest->getRequestId()->toString()]->isConsumed());

        $this->assertProtocolError('unauthorized_client', fn () => $this->decide($foreignRequest, clientId: $foreign->getPublicId()->toString(), approved: false));
        self::assertFalse($this->pushed[$foreignRequest->getRequestId()->toString()]->isConsumed(), 'Another user cannot burn the request by denying it.');
    }

    public function testExchangeIssuesAProofBoundTokenPairAndRedeemsTheCode(): void
    {
        $code = $this->issuedCode();

        $tokens = ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            credentials: new ClientCredentials($this->client->getPublicId()->toString()),
            code: $code,
            redirectUri: self::REDIRECT,
            codeVerifier: self::VERIFIER,
            dpopJkt: self::JKT,
            ipAddress: '192.0.2.10',
            userAgent: 'PHPUnit',
            clientFingerprint: 'fp-tv',
        ));

        self::assertSame('DPoP', $tokens->getTokenType());
        self::assertSame(self::JKT, $this->issuedAccessTokens[0]->getDpopJkt());
        self::assertSame([self::JKT], $this->jwtBindings);
        self::assertSame($this->issuedRefreshTokens[0]->getTokenId()->toString(), $tokens->getRefreshToken());
        self::assertSame('fp-tv', $this->issuedMetadata[0]->getClientFingerprint());
        self::assertSame(['library'], $tokens->getScopes());
        self::assertArrayHasKey($code, $this->redeemed);
        self::assertSame([['token', $this->client->getPublicId()->toString(), '192.0.2.10']], $this->clientCharges);
    }

    public function testExchangeWithAProofFromAnotherKeyIsRejectedAndTheCodeStaysUnredeemed(): void
    {
        $code = $this->issuedCode();

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code, jkt: self::OTHER_JKT)));
        self::assertSame([], $this->issuedAccessTokens);
        self::assertSame([], $this->redeemed);

        ($this->exchanger())($this->exchange($code));
        self::assertCount(1, $this->issuedAccessTokens, 'The bound key can still redeem the code.');
    }

    public function testACodeExchangedAfterItsLifetimeIsRejected(): void
    {
        $code = $this->issuedCode();
        $this->codes[$code]->getState()->expiresAt = new DateTimeImmutable('-1 second');

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code)));
        self::assertSame([], $this->issuedAccessTokens);
    }

    /** @return iterable<string, array{?string}> */
    public static function wrongVerifiers(): iterable
    {
        yield 'missing verifier' => [null];
        yield 'wrong verifier' => [str_repeat('x', 43)];
        yield 'challenge sent as a plain verifier' => [self::CHALLENGE];
    }

    #[DataProvider('wrongVerifiers')]
    public function testExchangeRejectsAMissingOrWrongVerifier(?string $verifier): void
    {
        $code = $this->issuedCode();

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code, verifier: $verifier)));
        self::assertSame([], $this->issuedAccessTokens);
        self::assertSame([], $this->redeemed);
    }

    public function testExchangeRejectsAnotherRedirectUri(): void
    {
        $code = $this->issuedCode();

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code, redirectUri: 'https://app.baander.app/other')));
        $this->assertProtocolError('invalid_request', fn () => ($this->exchanger())($this->exchange($code, redirectUri: null)));
    }

    public function testExchangeRejectsACodeOfAnotherClient(): void
    {
        $code = $this->issuedCode();
        $other = $this->register(Client::create('Other app', [self::REDIRECT]));

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            new ClientCredentials($other->getPublicId()->toString()), $code, self::REDIRECT, self::VERIFIER, self::JKT,
        )));
    }

    public function testACodeIsRedeemedOnce(): void
    {
        $code = $this->issuedCode();
        ($this->exchanger())($this->exchange($code));

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code)));
        self::assertCount(1, $this->issuedAccessTokens);
    }

    public function testPresentingARedeemedCodeAgainRevokesTheChainItProduced(): void
    {
        $code = $this->issuedCode();
        ($this->exchanger())($this->exchange($code));
        $chainId = $this->issuedAccessTokens[0]->getChainId();
        self::assertNotNull($chainId);
        self::assertSame([], $this->revokedChains);

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code)));

        self::assertSame([$chainId->toString()], $this->revokedChains);
        self::assertCount(1, $this->issuedAccessTokens);
    }

    public function testAnotherClientPresentingARedeemedCodeRevokesNothing(): void
    {
        $code = $this->issuedCode();
        ($this->exchanger())($this->exchange($code));
        $other = $this->register(Client::create('Other app', [self::REDIRECT]));

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            new ClientCredentials($other->getPublicId()->toString()), $code, self::REDIRECT, self::VERIFIER, self::JKT,
        )));

        self::assertSame([], $this->revokedChains);
    }

    public function testAConcurrentRedemptionLosesWithoutIssuingTokens(): void
    {
        $code = $this->issuedCode();
        $this->redeemed[$code] = true;

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code)));
        self::assertSame([], $this->issuedRefreshTokens);
        self::assertSame([], $this->revokedChains, 'The winner\'s chain is not ended by the loser of a race.');
    }

    public function testConfidentialClientsMustSendTheirSecret(): void
    {
        $confidential = $this->register(Client::create('Server app', [self::REDIRECT], secret: ClientSecret::fromString('shared-secret'), confidential: true));
        $code = $this->issuedCode($confidential);

        $this->assertProtocolError('invalid_client', fn () => ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            new ClientCredentials($confidential->getPublicId()->toString(), 'wrong'), $code, self::REDIRECT, self::VERIFIER, self::JKT,
        )), 401);

        $tokens = ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            new ClientCredentials($confidential->getPublicId()->toString(), 'shared-secret'), $code, self::REDIRECT, self::VERIFIER, self::JKT,
        ));
        self::assertNotSame('', $tokens->getAccessToken());
    }

    /** @param list<string> $scopes */
    private function pushRequest(?Client $client = null, string $redirectUri = self::REDIRECT, array $scopes = ['library']): PushedAuthorizationRequest
    {
        $request = PushedAuthorizationRequest::create(
            ($client ?? $this->client)->getId(),
            $redirectUri,
            array_map(static fn (string $scope): Scope => new Scope($scope), $scopes),
            'pushed-state',
            self::CHALLENGE,
            'S256',
            self::JKT,
            new DateInterval('PT300S'),
            $this->clock->now(),
        );
        $this->pushed[$request->getRequestId()->toString()] = $request;

        return $request;
    }

    private function issuedCode(?Client $client = null): string
    {
        $client ??= $this->client;
        $code = $this->decide($this->pushRequest($client), clientId: $client->getPublicId()->toString())->code;
        self::assertIsString($code);

        return $code;
    }

    private function decide(PushedAuthorizationRequest $request, ?string $clientId = null, bool $approved = true): AuthorizationResponseDTO
    {
        $handler = new CreateAuthorizationCodeHandler($this->validator(), $this->pushedRequests(), $this->authCodes(), new PassThroughTransaction(), 60);

        return $handler(new CreateAuthorizationCodeCommand(
            userId: $this->user->getId(),
            clientId: $clientId ?? $this->client->getPublicId()->toString(),
            requestUri: $request->requestUri(),
            approved: $approved,
        ));
    }

    private function describe(PushedAuthorizationRequest $request, ?string $clientId = null): AuthorizationRequestDTO
    {
        return (new GetAuthorizationRequestHandler($this->validator()))(new GetAuthorizationRequestQuery(
            userId: $this->user->getId(),
            clientId: $clientId ?? $this->client->getPublicId()->toString(),
            requestUri: $request->requestUri(),
        ));
    }

    private function validator(): AuthorizationRequestValidator
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->user);

        return new AuthorizationRequestValidator(
            $this->clientAuthenticator(),
            $users,
            new ScopeAllowlist(['profile', 'email', 'library', 'playlist']),
            $this->pushedRequests(),
            $this->clock,
        );
    }

    /** An in-memory store that uses a request up only while it is unexpired, unused and the named client's. */
    private function pushedRequests(): PushedAuthorizationRequestRepositoryInterface
    {
        $repository = $this->createStub(PushedAuthorizationRequestRepositoryInterface::class);
        $repository->method('findByRequestId')->willReturnCallback(fn (TokenId $id): ?PushedAuthorizationRequest => $this->pushed[$id->toString()] ?? null);
        $repository->method('consume')->willReturnCallback(function (TokenId $id, Uuid $clientId): bool {
            $request = $this->pushed[$id->toString()] ?? null;
            if ($request === null || $request->isConsumed() || $request->isExpired($this->clock->now()) || !$request->getClientId()->equals($clientId)) {
                return false;
            }
            $this->pushed[$id->toString()] = self::usedUp($request, $this->clock->now());

            return true;
        });

        return $repository;
    }

    private static function usedUp(PushedAuthorizationRequest $request, DateTimeImmutable $at): PushedAuthorizationRequest
    {
        return PushedAuthorizationRequest::reconstitute(new PushedAuthorizationRequestState(
            id: $request->getId(),
            requestId: $request->getRequestId(),
            clientId: $request->getClientId(),
            redirectUri: $request->getRedirectUri(),
            scopes: $request->getScopes(),
            state: $request->getState(),
            codeChallenge: $request->getCodeChallenge(),
            codeChallengeMethod: $request->getCodeChallengeMethod(),
            dpopJkt: $request->getDpopJkt(),
            expiresAt: $request->getExpiresAt(),
            createdAt: $request->getCreatedAt(),
            consumedAt: $at,
        ));
    }

    private function exchanger(): ExchangeAuthorizationCodeHandler
    {
        return new ExchangeAuthorizationCodeHandler($this->clientAuthenticator(), $this->authCodes(), $this->tokenPairIssuer(), $this->chainRevoker(), $this->clientThrottle());
    }

    private function exchange(string $code, ?string $redirectUri = self::REDIRECT, ?string $verifier = self::VERIFIER, string $jkt = self::JKT): ExchangeAuthorizationCodeCommand
    {
        return new ExchangeAuthorizationCodeCommand(new ClientCredentials($this->client->getPublicId()->toString()), $code, $redirectUri, $verifier, $jkt);
    }

    private function authCodes(): AuthCodeRepositoryInterface
    {
        $repository = $this->createStub(AuthCodeRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (AuthCode $code): void {
            $this->codes[$code->getCodeId()->toString()] = $code;
        });
        $repository->method('findByCodeId')->willReturnCallback(fn (TokenId $id): ?AuthCode => $this->codes[$id->toString()] ?? null);
        $repository->method('redeem')->willReturnCallback(function (AuthCode $code, ChainId $chainId): bool {
            $id = $code->getCodeId()->toString();
            if (isset($this->redeemed[$id])) {
                return false;
            }
            $this->redeemed[$id] = true;
            $code->redeemFor($chainId);

            return true;
        });

        return $repository;
    }

    /** An unusable request is shown to the user and never redirected to the client. */
    private function assertUnusableRequest(callable $action): void
    {
        $exception = $this->assertProtocolError('invalid_request_uri', $action);

        self::assertNull($exception->redirectUri);
    }

    private function assertProtocolError(string $error, callable $action, int $status = 400): OAuthProtocolException
    {
        try {
            $action();
        } catch (OAuthProtocolException $exception) {
            self::assertSame($error, $exception->error, $exception->getMessage());
            self::assertSame($status, $exception->statusCode);

            return $exception;
        }

        self::fail(sprintf('Expected the OAuth error "%s".', $error));
    }
}
