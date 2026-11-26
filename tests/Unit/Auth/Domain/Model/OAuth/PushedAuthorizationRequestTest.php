<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushedAuthorizationRequestTest extends TestCase
{
    private const string REDIRECT = 'https://app.baander.app/callback';
    // RFC 7636 appendix B.
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    // RFC 7638 section 3.1.
    private const string THUMBPRINT = 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs';

    public function testARequestExpiresAtTheEndOfItsLifetime(): void
    {
        $pushedAt = new DateTimeImmutable('2026-11-19T12:00:00.250000+00:00');
        $request = $this->request(pushedAt: $pushedAt);

        self::assertEquals($pushedAt->modify('+300 seconds'), $request->getExpiresAt());
        self::assertFalse($request->isExpired($pushedAt->modify('+299 seconds')));
        self::assertTrue($request->isExpired($pushedAt->modify('+301 seconds')));
        self::assertFalse($request->isConsumed());
    }

    public function testTheRequestUriIsTheRegisteredUrnFollowedByTheRequestId(): void
    {
        $request = $this->request();

        self::assertSame('urn:ietf:params:oauth:request_uri:' . $request->getRequestId()->toString(), $request->requestUri());
        $requestId = $request->getRequestId()->toString();
        self::assertSame(80, strlen($requestId), 'An unguessable token identifier.');
    }

    public function testTheRequestKeepsThePushedParameters(): void
    {
        $client = Uuid::v4();
        $request = $this->request(client: $client);

        self::assertTrue($client->equals($request->getClientId()));
        self::assertSame(self::REDIRECT, $request->getRedirectUri());
        self::assertSame(['library', 'playlist'], $request->getScopeIdentifiers());
        self::assertSame('state-123', $request->getState());
        self::assertSame(self::CHALLENGE, $request->getCodeChallenge());
        self::assertSame('S256', $request->getCodeChallengeMethod());
        self::assertSame(self::THUMBPRINT, $request->getDpopJkt());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidParameters(): iterable
    {
        yield 'plain PKCE method' => [['codeChallengeMethod' => 'plain']];
        yield 'challenge that is no S256 digest' => [['codeChallenge' => 'too-short']];
        yield 'thumbprint that is no SHA-256 digest' => [['dpopJkt' => 'too-short']];
        yield 'empty redirect URI' => [['redirectUri' => ' ']];
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRejected(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        PushedAuthorizationRequest::create(...[
            'clientId' => Uuid::v4(),
            'redirectUri' => self::REDIRECT,
            'scopes' => [],
            'state' => null,
            'codeChallenge' => self::CHALLENGE,
            'codeChallengeMethod' => 'S256',
            'dpopJkt' => self::THUMBPRINT,
            'lifetime' => new DateInterval('PT300S'),
            'now' => new DateTimeImmutable(),
            ...$overrides,
        ]);
    }

    private function request(?Uuid $client = null, ?DateTimeImmutable $pushedAt = null): PushedAuthorizationRequest
    {
        return PushedAuthorizationRequest::create(
            clientId: $client ?? Uuid::v4(),
            redirectUri: self::REDIRECT,
            scopes: [new Scope('library'), new Scope('playlist')],
            state: 'state-123',
            codeChallenge: self::CHALLENGE,
            codeChallengeMethod: 'S256',
            dpopJkt: self::THUMBPRINT,
            lifetime: new DateInterval('PT300S'),
            now: $pushedAt ?? new DateTimeImmutable(),
        );
    }
}
