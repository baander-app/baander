<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\PushAuthorizationRequestCommand;
use App\Auth\Application\CommandHandler\OAuth\PushAuthorizationRequestHandler;
use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\DTO\PushedAuthorizationRequestDTO;
use App\Auth\Application\Exception\OAuthClientRateLimitedException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\PushedAuthorizationRequestRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A client pushes its authorization request on the back channel (RFC 9126) with a key
 * binding (RFC 9449 section 10.1) and receives a request URI for the browser.
 */
final class PushAuthorizationRequestHandlerTest extends TestCase
{
    use IssuesTokenPairs;

    private const string REDIRECT = 'https://app.baander.app/callback';
    // RFC 7636 appendix B.
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const string KEY_ONE = 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs';
    private const string KEY_TWO = '0ZcOCORZNYy-DWpqq30jZyJGHTN0d2HglBV3uiguA4I';

    /** @var list<PushedAuthorizationRequest> */
    private array $saved = [];
    private Client $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19 12:00:00');
        $this->client = $this->register(Client::create('Third-party player', [self::REDIRECT, 'https://app.baander.app/other']));
    }

    public function testAProofBindsTheRequestToItsKey(): void
    {
        $answer = $this->push(proofJkt: self::KEY_ONE);

        self::assertStringStartsWith('urn:ietf:params:oauth:request_uri:', $answer->requestUri);
        self::assertSame(300, $answer->expiresIn);
        $stored = $this->stored();
        self::assertSame($answer->requestUri, $stored->requestUri());
        self::assertSame(self::KEY_ONE, $stored->getDpopJkt());
        self::assertSame(self::REDIRECT, $stored->getRedirectUri());
        self::assertSame(self::CHALLENGE, $stored->getCodeChallenge());
        self::assertSame('state-1', $stored->getState());
        self::assertSame(['library'], $stored->getScopeIdentifiers(), 'Scopes outside the allowlist are dropped.');
        self::assertTrue($this->client->getId()->equals($stored->getClientId()));
        self::assertEquals($this->clock->now()->modify('+300 seconds'), $stored->getExpiresAt());
    }

    public function testTheDpopJktParameterAloneBindsTheRequest(): void
    {
        $this->push(dpopJkt: self::KEY_TWO);

        self::assertSame(self::KEY_TWO, $this->stored()->getDpopJkt());
    }

    public function testAProofAndTheSameDpopJktBindTheRequest(): void
    {
        $this->push(proofJkt: self::KEY_ONE, dpopJkt: self::KEY_ONE);

        self::assertSame(self::KEY_ONE, $this->stored()->getDpopJkt());
    }

    public function testAStateIsAtMost1024Bytes(): void
    {
        $this->push(proofJkt: self::KEY_ONE, state: str_repeat('é', 512));
        self::assertSame(str_repeat('é', 512), $this->stored()->getState());

        $this->assertRejected('invalid_request', fn () => $this->push(proofJkt: self::KEY_ONE, state: str_repeat('é', 512) . 's'));
    }

    public function testARequestWithoutAKeyBindingIsRejected(): void
    {
        $this->assertRejected('invalid_request', fn () => $this->push());
    }

    public function testAProofAndADifferentDpopJktAreRejected(): void
    {
        $this->assertRejected('invalid_dpop_proof', fn () => $this->push(proofJkt: self::KEY_ONE, dpopJkt: self::KEY_TWO));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedThumbprints(): iterable
    {
        yield 'too short' => [substr(self::KEY_ONE, 0, 42)];
        yield 'too long' => [self::KEY_ONE . 'A'];
        yield 'padded' => [substr(self::KEY_ONE, 0, 42) . '='];
        yield 'standard base64 characters' => [substr(self::KEY_ONE, 0, 41) . '+/'];
    }

    #[DataProvider('malformedThumbprints')]
    public function testAMalformedDpopJktIsRejected(string $thumbprint): void
    {
        $this->assertRejected('invalid_request', fn () => $this->push(dpopJkt: $thumbprint));
    }

    public function testAConfidentialClientMustSendItsSecret(): void
    {
        $confidential = $this->register(Client::create('Server app', [self::REDIRECT], secret: ClientSecret::fromString('shared-secret'), confidential: true));

        $this->assertRejected('invalid_client', fn () => $this->push(clientId: $confidential->getPublicId()->toString(), clientSecret: 'wrong', proofJkt: self::KEY_ONE), 401);
        $this->push(clientId: $confidential->getPublicId()->toString(), clientSecret: 'shared-secret', proofJkt: self::KEY_ONE);
        self::assertCount(1, $this->saved);
    }

    public function testThePushIsChargedToTheAuthenticatedClientAndTheCallerAddress(): void
    {
        $this->push(proofJkt: self::KEY_ONE);

        self::assertSame([['par', $this->client->getPublicId()->toString(), '198.51.100.7']], $this->clientCharges);
    }

    public function testAFailedClientAuthenticationChargesNoClient(): void
    {
        $confidential = $this->register(Client::create('Server app', [self::REDIRECT], secret: ClientSecret::fromString('shared-secret'), confidential: true));

        $this->assertRejected('invalid_client', fn () => $this->push(clientId: $confidential->getPublicId()->toString(), clientSecret: 'wrong', proofJkt: self::KEY_ONE), 401);
        self::assertSame([], $this->clientCharges);
    }

    public function testAClientOverItsAllowanceStoresNothing(): void
    {
        $this->clientAllowanceSpentFor = 30;

        try {
            $this->push(proofJkt: self::KEY_ONE);
            self::fail('A client over its allowance must be refused.');
        } catch (OAuthClientRateLimitedException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds);
        }
        self::assertSame([], $this->saved);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function ungrantableScopes(): iterable
    {
        yield 'no scope' => [[]];
        yield 'only a scope the server does not grant' => [['admin']];
    }

    /** @param list<string> $scopes */
    #[DataProvider('ungrantableScopes')]
    public function testAPushWithNothingGrantableIsRefusedWithInvalidScope(array $scopes): void
    {
        $this->assertRejected('invalid_scope', fn () => $this->push(proofJkt: self::KEY_ONE, scopes: $scopes));
        self::assertSame([], $this->saved);
    }

    public function testAnUnknownClientIsRejected(): void
    {
        $this->assertRejected('invalid_client', fn () => $this->push(clientId: 'unknown_client_000001', proofJkt: self::KEY_ONE), 401);
    }

    public function testDeviceClientsCannotPushAuthorizationRequests(): void
    {
        $device = $this->register(Client::create('TV', [self::REDIRECT], deviceClient: true));

        $this->assertRejected('unauthorized_client', fn () => $this->push(clientId: $device->getPublicId()->toString(), proofJkt: self::KEY_ONE));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function invalidPkceRequests(): iterable
    {
        yield 'no challenge' => [null, 'S256'];
        yield 'no method (would mean plain)' => [self::CHALLENGE, null];
        yield 'plain method' => ['dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'plain'];
        yield 'challenge that is no S256 digest' => ['short', 'S256'];
    }

    #[DataProvider('invalidPkceRequests')]
    public function testAPushRequiresAnS256Challenge(?string $challenge, ?string $method): void
    {
        $this->assertRejected('invalid_request', fn () => $this->push(challenge: $challenge, method: $method, proofJkt: self::KEY_ONE));
    }

    public function testOnlyTheCodeResponseTypeIsSupported(): void
    {
        $this->assertRejected('unsupported_response_type', fn () => $this->push(responseType: 'token', proofJkt: self::KEY_ONE));
    }

    public function testAnUnregisteredRedirectUriIsRejected(): void
    {
        $this->assertRejected('invalid_request', fn () => $this->push(redirectUri: 'https://evil.baander.app/callback', proofJkt: self::KEY_ONE));
    }

    public function testRedirectUriMayBeOmittedOnlyForASingleRegisteredUri(): void
    {
        $this->assertRejected('invalid_request', fn () => $this->push(redirectUri: null, proofJkt: self::KEY_ONE));

        $single = $this->register(Client::create('Single', [self::REDIRECT]));
        $this->push(clientId: $single->getPublicId()->toString(), redirectUri: null, proofJkt: self::KEY_ONE);
        self::assertSame(self::REDIRECT, $this->stored()->getRedirectUri());
    }

    public function testLoopbackRedirectUrisMatchOnAnyPort(): void
    {
        $native = $this->register(Client::createPersonalAccess('CLI', Uuid::generate()));

        $this->push(clientId: $native->getPublicId()->toString(), redirectUri: 'http://localhost:53682', proofJkt: self::KEY_ONE);
        self::assertSame('http://localhost:53682', $this->stored()->getRedirectUri());
        $this->assertRejected('invalid_request', fn () => $this->push(clientId: $native->getPublicId()->toString(), redirectUri: 'http://localhost.baander.app:53682', proofJkt: self::KEY_ONE));
    }

    /** @param list<string> $scopes */
    private function push(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $redirectUri = self::REDIRECT,
        ?string $challenge = self::CHALLENGE,
        ?string $method = 'S256',
        ?string $responseType = 'code',
        ?string $proofJkt = null,
        ?string $dpopJkt = null,
        ?string $state = 'state-1',
        array $scopes = ['library', 'admin'],
    ): PushedAuthorizationRequestDTO {
        $handler = new PushAuthorizationRequestHandler(
            $this->clientAuthenticator(),
            new AuthorizationRequestValidator(
                $this->clientAuthenticator(),
                $this->createStub(UserRepositoryInterface::class),
                new ScopeAllowlist(['profile', 'email', 'library', 'playlist']),
                $this->pushedRequests(),
                $this->clock,
            ),
            $this->pushedRequests(),
            $this->clientThrottle(),
            $this->clock,
            300,
        );

        return $handler(new PushAuthorizationRequestCommand(
            credentials: new ClientCredentials($clientId ?? $this->client->getPublicId()->toString(), $clientSecret),
            responseType: $responseType,
            redirectUri: $redirectUri,
            scopes: $scopes,
            state: $state,
            codeChallenge: $challenge,
            codeChallengeMethod: $method,
            proofJkt: $proofJkt,
            dpopJkt: $dpopJkt,
            ipAddress: '198.51.100.7',
        ));
    }

    private function pushedRequests(): PushedAuthorizationRequestRepositoryInterface
    {
        $repository = $this->createStub(PushedAuthorizationRequestRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (PushedAuthorizationRequest $request): void {
            $this->saved[] = $request;
        });

        return $repository;
    }

    private function stored(): PushedAuthorizationRequest
    {
        $stored = array_last($this->saved);
        self::assertInstanceOf(PushedAuthorizationRequest::class, $stored);

        return $stored;
    }

    /** Errors at the pushed authorization request endpoint answer the client directly, never at a redirect URI. */
    private function assertRejected(string $error, callable $action, int $status = 400): void
    {
        $stored = count($this->saved);
        try {
            $action();
        } catch (OAuthProtocolException $exception) {
            self::assertSame($error, $exception->error, $exception->getMessage());
            self::assertSame($status, $exception->statusCode);
            self::assertNull($exception->redirectUri);
            self::assertCount($stored, $this->saved, 'A rejected request stores nothing.');

            return;
        }

        self::fail(sprintf('Expected the OAuth error "%s".', $error));
    }
}
