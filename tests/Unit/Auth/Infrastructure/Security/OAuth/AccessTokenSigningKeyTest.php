<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Security\OAuth\AccessTokenSigningKey;
use App\Auth\Infrastructure\Security\OAuth\JwtGenerator;
use App\Shared\Domain\Model\Email;
use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\TestCase;

/** Access tokens name their issuer, type and key, and the key recognizes the tokens it signed. */
final class AccessTokenSigningKeyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testATokenCarriesTheIssuerTheAccessTokenTypeAndTheKeyIdTheJwksPublishes(): void
    {
        [$private, $public] = $this->keyPair();
        $token = AccessToken::issue(Client::create('Player', ['https://player.baander.app/callback']), SignInGrant::AuthorizationCode, User::register(new Email('kid@baander.app'), 'hashed', 'Kid'));

        $jwt = (new Parser(new JoseEncoder()))->parse((new JwtGenerator($private, 'https://baander.app'))->generate($token, 'proof-thumbprint'));

        self::assertInstanceOf(UnencryptedToken::class, $jwt);
        self::assertSame('https://baander.app', $jwt->claims()->get('iss'));
        self::assertSame(['https://baander.app'], $jwt->claims()->get('aud'));
        self::assertSame('at+jwt', $jwt->headers()->get('typ'));
        $jwk = (new AccessTokenSigningKey($public))->publicJwk();
        self::assertSame($jwk['kid'], $jwt->headers()->get('kid'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $jwk['kid'], 'The kid is the RFC 7638 SHA-256 thumbprint.');
        self::assertSame(['kty', 'use', 'alg', 'kid', 'n', 'e'], array_keys($jwk));
    }

    public function testTheKeyReadsTheJtiOfATokenItSignedEvenWhenExpired(): void
    {
        [$private, $public] = $this->keyPair();
        $expiry = new DateInterval('PT1H');
        $expiry->invert = 1;
        $token = AccessToken::issue(Client::create('Player', ['https://player.baander.app/callback']), SignInGrant::AuthorizationCode, ttl: $expiry);
        $jwt = (new JwtGenerator($private, 'https://baander.app'))->generate($token);

        self::assertSame($token->getTokenId()->toString(), (new AccessTokenSigningKey($public))->tokenIdOf($jwt));
    }

    public function testATokenSignedByAnotherKeyAndAStringThatIsNoJwtHaveNoTokenId(): void
    {
        [, $public] = $this->keyPair();
        [$otherPrivate] = $this->keyPair();
        $foreign = (new JwtGenerator($otherPrivate, 'https://baander.app'))->generate(AccessToken::issue(Client::create('Player', ['https://player.baander.app/callback']), SignInGrant::AuthorizationCode));
        $key = new AccessTokenSigningKey($public);

        self::assertNull($key->tokenIdOf($foreign));
        self::assertNull($key->tokenIdOf('not-a-jwt'));
        self::assertNull($key->tokenIdOf(''));
    }

    /** @return array{string, string} Paths of a fresh private and public PEM file */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);

        return [$this->file($privatePem), $this->file($details['key'])];
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-jwt-');
        self::assertNotFalse($path);
        chmod($path, 0600);
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
