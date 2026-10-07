<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\CommandHandler\OAuth\CreateAuthorizationCodeHandler;
use App\Auth\Application\CommandHandler\OAuth\ExchangeAuthorizationCodeHandler;
use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Query\OAuth\GetAuthorizationRequestQuery;
use App\Auth\Application\QueryHandler\OAuth\GetAuthorizationRequestHandler;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The authorization code grant with mandatory S256 PKCE, from the authorization
 * request to the token exchange (RFC 6749 section 4.1, RFC 7636).
 */
final class AuthorizationCodeGrantTest extends TestCase
{
    use IssuesTokenPairs;

    private const string REDIRECT = 'https://app.baander.app/callback';
    private const string JKT = 'proof-key-thumbprint';
    // RFC 7636 appendix B.
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @var array<string, AuthCode> */
    private array $codes = [];
    /** @var array<string, true> */
    private array $redeemed = [];
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('listener@baander.app'), 'hashed', 'Listener');
        $this->client = $this->register(Client::create('Third-party player', [self::REDIRECT, 'https://app.baander.app/other']));
    }

    public function testAuthorizationIssuesAPkceBoundCode(): void
    {
        $result = $this->authorize();

        self::assertSame(self::REDIRECT, $result->redirectUri);
        self::assertIsString($result->code);
        $code = $this->codes[$result->code];
        self::assertSame(self::CHALLENGE, $code->getCodeChallenge());
        self::assertSame(self::REDIRECT, $code->getRedirectUri());
        self::assertSame(['library'], $code->getScopeIdentifiers(), 'Scopes outside the allowlist are dropped.');
        self::assertNotNull($code->getExpiresAt());
    }

    public function testTheConsentPageSeesTheClientAndTheGrantedScopesWithoutACodeBeingIssued(): void
    {
        $request = $this->describe();

        self::assertSame($this->client->getPublicId()->toString(), $request->clientId);
        self::assertSame('Third-party player', $request->clientName);
        self::assertSame('public', $request->clientType);
        self::assertSame(['library'], $request->scopes);
        self::assertSame(self::REDIRECT, $request->redirectUri);
        self::assertTrue($request->consentRequired);
        self::assertSame([], $this->codes);
        self::assertSame(['access-api'], $this->describe(scopes: ['admin'])->scopes, 'No allowed scope means the default scopes.');
    }

    public function testTheConsentCheckRedirectsParameterErrorsOnly(): void
    {
        $exception = $this->assertProtocolError('invalid_request', fn () => $this->describe(method: 'plain'));
        self::assertSame(self::REDIRECT, $exception->redirectUri);

        $unknown = $this->assertProtocolError('invalid_client', fn () => $this->describe(clientId: 'unknown_client_000001'), 401);
        self::assertNull($unknown->redirectUri);
    }

    public function testDenialAnswersAccessDeniedAtTheRedirectUriWithoutACode(): void
    {
        $answer = $this->authorize(approved: false);

        self::assertNull($answer->code);
        self::assertSame('access_denied', $answer->error);
        self::assertSame(self::REDIRECT, $answer->redirectUri);
        self::assertSame([], $this->codes);

        $this->assertProtocolError('invalid_request', fn () => $this->authorize(redirectUri: 'https://evil.baander.app/callback', approved: false));
    }

    public function testExchangeIssuesAProofBoundTokenPairAndRedeemsTheCode(): void
    {
        $code = (string) $this->authorize()->code;

        $tokens = ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            clientId: $this->client->getPublicId()->toString(),
            clientSecret: null,
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
        $code = (string) $this->authorize()->code;

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code, verifier: $verifier)));
        self::assertSame([], $this->issuedAccessTokens);
        self::assertSame([], $this->redeemed);
    }

    public function testExchangeRejectsAnotherRedirectUri(): void
    {
        $code = (string) $this->authorize()->code;

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code, redirectUri: 'https://app.baander.app/other')));
        $this->assertProtocolError('invalid_request', fn () => ($this->exchanger())($this->exchange($code, redirectUri: null)));
    }

    public function testExchangeRejectsACodeOfAnotherClient(): void
    {
        $code = (string) $this->authorize()->code;
        $other = $this->register(Client::create('Other app', [self::REDIRECT]));

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            $other->getPublicId()->toString(), null, $code, self::REDIRECT, self::VERIFIER, self::JKT,
        )));
    }

    public function testACodeIsRedeemedOnce(): void
    {
        $code = (string) $this->authorize()->code;
        ($this->exchanger())($this->exchange($code));

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code)));
        self::assertCount(1, $this->issuedAccessTokens);
    }

    public function testAConcurrentRedemptionLosesWithoutIssuingTokens(): void
    {
        $code = (string) $this->authorize()->code;
        $this->redeemed[$code] = true;

        $this->assertProtocolError('invalid_grant', fn () => ($this->exchanger())($this->exchange($code)));
        self::assertSame([], $this->issuedRefreshTokens);
    }

    public function testConfidentialClientsMustSendTheirSecret(): void
    {
        $confidential = $this->register(Client::create('Server app', [self::REDIRECT], secret: ClientSecret::fromString('shared-secret'), confidential: true));
        $code = (string) $this->authorize(clientId: $confidential->getPublicId()->toString())->code;

        $this->assertProtocolError('invalid_client', fn () => ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            $confidential->getPublicId()->toString(), 'wrong', $code, self::REDIRECT, self::VERIFIER, self::JKT,
        )), 401);

        $tokens = ($this->exchanger())(new ExchangeAuthorizationCodeCommand(
            $confidential->getPublicId()->toString(), 'shared-secret', $code, self::REDIRECT, self::VERIFIER, self::JKT,
        ));
        self::assertNotSame('', $tokens->getAccessToken());
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function invalidPkceRequests(): iterable
    {
        yield 'no challenge' => [null, 'S256', 'invalid_request'];
        yield 'no method (would mean plain)' => [self::CHALLENGE, null, 'invalid_request'];
        yield 'plain method' => [self::VERIFIER, 'plain', 'invalid_request'];
        yield 'challenge that is no S256 digest' => ['short', 'S256', 'invalid_request'];
    }

    #[DataProvider('invalidPkceRequests')]
    public function testAuthorizationRequiresAnS256Challenge(?string $challenge, ?string $method, string $error): void
    {
        $exception = $this->assertProtocolError($error, fn () => $this->authorize(challenge: $challenge, method: $method));

        self::assertSame(self::REDIRECT, $exception->redirectUri, 'Once the redirect URI is valid, errors go to the client.');
        self::assertSame([], $this->codes);
    }

    public function testUnknownClientOrRedirectUriIsNotRedirected(): void
    {
        $unknownClient = $this->assertProtocolError('invalid_client', fn () => $this->authorize(clientId: 'unknown_client_000001'), 401);
        self::assertNull($unknownClient->redirectUri);

        $foreignRedirect = $this->assertProtocolError('invalid_request', fn () => $this->authorize(redirectUri: 'https://evil.baander.app/callback'));
        self::assertNull($foreignRedirect->redirectUri);
    }

    public function testRedirectUriMayBeOmittedOnlyForASingleRegisteredUri(): void
    {
        $this->assertProtocolError('invalid_request', fn () => $this->authorize(redirectUri: null));

        $single = $this->register(Client::create('Single', [self::REDIRECT]));
        self::assertSame(self::REDIRECT, $this->authorize(clientId: $single->getPublicId()->toString(), redirectUri: null)->redirectUri);
    }

    public function testLoopbackRedirectUrisMatchOnAnyPort(): void
    {
        $native = $this->register(Client::createPersonalAccess('CLI', $this->user->getId()));

        $result = $this->authorize(clientId: $native->getPublicId()->toString(), redirectUri: 'http://localhost:53682');

        self::assertSame('http://localhost:53682', $result->redirectUri);
        $this->assertProtocolError('invalid_request', fn () => $this->authorize(clientId: $native->getPublicId()->toString(), redirectUri: 'http://localhost.baander.app:53682'));
    }

    public function testAPersonalAccessClientServesOnlyItsOwnerWithoutConsent(): void
    {
        $own = $this->register(Client::createPersonalAccess('CLI', $this->user->getId()));
        $foreign = $this->register(Client::createPersonalAccess('Other CLI', \App\Shared\Domain\Model\Uuid::generate()));

        self::assertFalse($this->describe(clientId: $own->getPublicId()->toString(), redirectUri: 'http://localhost')->consentRequired);
        $exception = $this->assertProtocolError('unauthorized_client', fn () => $this->authorize(clientId: $foreign->getPublicId()->toString(), redirectUri: 'http://localhost'));
        self::assertSame('http://localhost', $exception->redirectUri);
    }

    public function testOnlyTheCodeResponseTypeIsSupported(): void
    {
        $this->assertProtocolError('unsupported_response_type', fn () => $this->authorize(responseType: 'token'));
    }

    public function testDeviceClientsCannotUseTheAuthorizationEndpoint(): void
    {
        $device = $this->register(Client::create('TV', [self::REDIRECT], deviceClient: true));

        $this->assertProtocolError('unauthorized_client', fn () => $this->authorize(clientId: $device->getPublicId()->toString()));
    }

    private function authorize(
        ?string $clientId = null,
        ?string $redirectUri = self::REDIRECT,
        ?string $challenge = self::CHALLENGE,
        ?string $method = 'S256',
        string $responseType = 'code',
        bool $approved = true,
    ): AuthorizationResponseDTO {
        $handler = new CreateAuthorizationCodeHandler($this->validator(), $this->authCodes(), 600);

        return $handler(new CreateAuthorizationCodeCommand(
            userId: $this->user->getId(),
            responseType: $responseType,
            clientId: $clientId ?? $this->client->getPublicId()->toString(),
            redirectUri: $redirectUri,
            codeChallenge: $challenge,
            codeChallengeMethod: $method,
            scopes: ['library', 'admin'],
            approved: $approved,
        ));
    }

    /** @param string[] $scopes */
    private function describe(?string $clientId = null, ?string $method = 'S256', array $scopes = ['library', 'admin'], string $redirectUri = self::REDIRECT): AuthorizationRequestDTO
    {
        return (new GetAuthorizationRequestHandler($this->validator()))(new GetAuthorizationRequestQuery(
            userId: $this->user->getId(),
            responseType: 'code',
            clientId: $clientId ?? $this->client->getPublicId()->toString(),
            redirectUri: $redirectUri,
            codeChallenge: self::CHALLENGE,
            codeChallengeMethod: $method,
            scopes: $scopes,
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
        );
    }

    private function exchanger(): ExchangeAuthorizationCodeHandler
    {
        return new ExchangeAuthorizationCodeHandler($this->clientAuthenticator(), $this->authCodes(), $this->tokenPairIssuer());
    }

    private function exchange(string $code, ?string $redirectUri = self::REDIRECT, ?string $verifier = self::VERIFIER): ExchangeAuthorizationCodeCommand
    {
        return new ExchangeAuthorizationCodeCommand($this->client->getPublicId()->toString(), null, $code, $redirectUri, $verifier, self::JKT);
    }

    private function authCodes(): AuthCodeRepositoryInterface
    {
        $repository = $this->createStub(AuthCodeRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (AuthCode $code): void {
            $this->codes[$code->getCodeId()->toString()] = $code;
        });
        $repository->method('findByCodeId')->willReturnCallback(fn (TokenId $id): ?AuthCode => $this->codes[$id->toString()] ?? null);
        $repository->method('redeem')->willReturnCallback(function (AuthCode $code): bool {
            $id = $code->getCodeId()->toString();
            if (isset($this->redeemed[$id])) {
                return false;
            }
            $this->redeemed[$id] = true;
            $code->revoke();

            return true;
        });

        return $repository;
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
