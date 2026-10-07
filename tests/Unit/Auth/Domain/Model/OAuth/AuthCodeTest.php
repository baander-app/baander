<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\AuthCodeState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthCodeTest extends TestCase
{
    private const string REDIRECT = 'https://app.baander.app/callback';
    // RFC 7636 appendix B.
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private Client $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = Client::create('Test', [self::REDIRECT]);
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
    }

    public function testCreateWithMinimalParams(): void
    {
        $code = $this->code();

        $this->assertNull($code->getExpiresAt());
        $this->assertFalse($code->isExpired());
        $this->assertFalse($code->isRevoked());
        $this->assertSame($this->user, $code->getUser());
        $this->assertSame($this->client, $code->getClient());
        $this->assertEmpty($code->getScopes());
        $this->assertSame(self::REDIRECT, $code->getRedirectUri());
        $this->assertSame(self::CHALLENGE, $code->getCodeChallenge());
        $this->assertSame('S256', $code->getCodeChallengeMethod());
    }

    public function testCreateWithTtl(): void
    {
        $code = $this->code([new Scope('profile')], new DateInterval('PT10M'));

        $this->assertNotNull($code->getExpiresAt());
        $this->assertFalse($code->isExpired());
        $this->assertSame(['profile'], $code->getScopeIdentifiers());
    }

    public function testCreateWithExpiredTtl(): void
    {
        $code = $this->code(ttl: new DateInterval('PT0S'));
        usleep(1000);

        $this->assertTrue($code->isExpired());
    }

    public function testThePlainMethodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthCode::create($this->user, $this->client, self::REDIRECT, self::VERIFIER, 'plain');
    }

    public function testAChallengeThatIsNotAnS256DigestIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthCode::create($this->user, $this->client, self::REDIRECT, 'too-short', 'S256');
    }

    public function testAnEmptyRedirectUriIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthCode::create($this->user, $this->client, ' ', self::CHALLENGE, 'S256');
    }

    public function testTheMatchingVerifierIsAccepted(): void
    {
        $this->assertTrue($this->code()->matchesCodeVerifier(self::VERIFIER));
    }

    /** @return iterable<string, array{?string}> */
    public static function wrongVerifiers(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'another verifier' => [str_repeat('a', 43)];
        yield 'the challenge itself, as a plain verifier' => [self::CHALLENGE];
        yield 'too short' => ['dBjftJeZ4CVP'];
        yield 'invalid characters' => [self::VERIFIER . '!'];
    }

    #[DataProvider('wrongVerifiers')]
    public function testAWrongVerifierIsRejected(?string $verifier): void
    {
        $this->assertFalse($this->code()->matchesCodeVerifier($verifier));
    }

    public function testTheCodeIsBoundToItsRedirectUri(): void
    {
        $code = $this->code();

        $this->assertTrue($code->isIssuedFor(self::REDIRECT));
        $this->assertFalse($code->isIssuedFor(self::REDIRECT . '/other'));
    }

    public function testRevoke(): void
    {
        $code = $this->code();

        $code->revoke();

        $this->assertTrue($code->isRevoked());
    }

    public function testRevokeIdempotent(): void
    {
        $code = $this->code();
        $code->revoke();
        $before = $code->getUpdatedAt();

        $code->revoke();

        $this->assertEquals($before, $code->getUpdatedAt());
    }

    public function testReconstitute(): void
    {
        $now = new \DateTimeImmutable();

        $code = AuthCode::reconstitute(new AuthCodeState(
            id: Uuid::v4(),
            codeId: TokenId::generate(),
            user: $this->user,
            client: $this->client,
            scopes: [],
            expiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            redirectUri: self::REDIRECT,
            codeChallenge: self::CHALLENGE,
            codeChallengeMethod: 'S256',
            revoked: true,
        ));

        $this->assertTrue($code->isRevoked());
    }

    /** @param Scope[] $scopes */
    private function code(array $scopes = [], ?DateInterval $ttl = null): AuthCode
    {
        return AuthCode::create($this->user, $this->client, self::REDIRECT, self::CHALLENGE, 'S256', $scopes, $ttl);
    }
}
