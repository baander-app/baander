<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\CryptKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccessTokenJwtTest extends TestCase
{
    #[DataProvider('subjects')]
    public function testSignsResourceAudienceAndIdentityClaims(bool $withUser): void
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rsa);
        self::assertTrue(openssl_pkey_export($rsa, $pem));
        $details = openssl_pkey_get_details($rsa);
        self::assertNotFalse($details);
        $client = new ClientEntity(new PublicId(), 'JWT client', '["https://baander.app/callback"]');
        $user = $withUser ? new UserEntity(new PublicId(), 'JWT user', 'jwt@baander.app', 'hashed', '', id: Uuid::generate()) : null;
        $expiry = new \DateTimeImmutable('+10 minutes');
        $entity = new AccessTokenEntity('jwt-token-identifier', $client, $user, scopes: ['profile', 'library:read'], expiresAt: $expiry);
        $entity->setPrivateKey(new CryptKey($pem));
        $entity->setResourceServerUri('https://baander.app');
        $entity->setDpopJkt(str_repeat('a', 43));

        $serialized = $entity->toString();
        $jwt = new Parser(new JoseEncoder())->parse($serialized);

        self::assertInstanceOf(UnencryptedToken::class, $jwt);
        self::assertSame('RS256', $jwt->headers()->get('alg'));
        self::assertTrue(new Sha256()->verify($jwt->signature()->hash(), $jwt->payload(), InMemory::plainText($details['key'])));
        self::assertSame(['https://baander.app'], $jwt->claims()->get('aud'));
        self::assertSame('jwt-token-identifier', $jwt->claims()->get('jti'));
        self::assertSame($user?->getId()->toString() ?? $client->getId()->toString(), $jwt->claims()->get('sub'));
        self::assertSame($client->getId()->toString(), $jwt->claims()->get('client_id'));
        self::assertSame(['profile', 'library:read'], $jwt->claims()->get('scopes'));
        self::assertSame(['jkt' => str_repeat('a', 43)], $jwt->claims()->get('cnf'));
        self::assertSame($expiry->getTimestamp(), $jwt->claims()->get('exp')->getTimestamp());
        self::assertNotSame($entity->getIdentifier(), $serialized);
    }

    public function testUnconfiguredEntityCannotReturnAnOpaqueTokenAsAccessToken(): void
    {
        $entity = new AccessTokenEntity('opaque-token', new ClientEntity(new PublicId(), 'JWT client', '[]'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access token signing requires a key, audience and expiry.');
        $entity->toString();
    }

    public function testInvalidSigningKeyFailsInsteadOfReturningTokenIdentifier(): void
    {
        // EC PEM is valid key material but cannot sign the configured RS256 algorithm.
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($ec);
        self::assertTrue(openssl_pkey_export($ec, $pem));
        $entity = new AccessTokenEntity('opaque-token', new ClientEntity(new PublicId(), 'JWT client', '[]'), expiresAt: new \DateTimeImmutable('+10 minutes'));
        $entity->setPrivateKey(new CryptKey($pem));
        $entity->setResourceServerUri('https://baander.app');
        $this->expectException(\Lcobucci\JWT\Signer\InvalidKeyProvided::class);
        $entity->toString();
    }

    /** @return iterable<string, array{bool}> */
    public static function subjects(): iterable
    {
        yield 'user identity' => [true];
        yield 'client identity' => [false];
    }
}
