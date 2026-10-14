<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Fixtures;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\TokenMetadataEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthAccessTokenJwtTest extends TestCase
{
    private static string $privateKey;
    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::$publicKey = $details['key'];
    }

    public function testNoOverridesSignsTheRowClaims(): void
    {
        $token = $this->token(new \DateTimeImmutable('+1 hour'));
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);

        $claims = $this->claims($jwt);
        self::assertSame('https://baander.app', $claims['aud']);
        self::assertSame($token->getTokenId(), $claims['jti']);
        self::assertSame($token->getUserIdentifier(), $claims['sub']);
        self::assertSame($token->getClient()->getIdentifier(), $claims['client_id']);
        self::assertSame(['library'], $claims['scopes']);
        self::assertSame(['jkt' => 'thumbprint-of-the-row'], $claims['cnf']);
        self::assertSame($token->getExpiryDateTime()->getTimestamp(), $claims['exp']);
        self::assertTrue($this->signatureVerifies($jwt));
    }

    public function testAudienceArgumentIsTheAudClaim(): void
    {
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $this->token(new \DateTimeImmutable('+1 hour')), 'https://other.baander.app');

        self::assertSame('https://other.baander.app', $this->claims($jwt)['aud']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function audienceOverrides(): iterable
    {
        yield 'list' => [['https://baander.app', 'https://other.baander.app']];
        yield 'object' => [['uri' => 'https://baander.app']];
        yield 'list with an integer element' => [['https://baander.app', 42]];
    }

    #[DataProvider('audienceOverrides')]
    public function testAnOverrideLandsVerbatimInThePayload(mixed $audience): void
    {
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $this->token(new \DateTimeImmutable('+1 hour')), claims: ['aud' => $audience]);

        self::assertSame($audience, $this->claims($jwt)['aud']);
    }

    public function testANullOverrideRemovesTheClaim(): void
    {
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $this->token(new \DateTimeImmutable('+1 hour')), claims: ['aud' => null]);

        self::assertArrayNotHasKey('aud', $this->claims($jwt));
    }

    public function testAnOverrideKeepsTheOtherRowClaims(): void
    {
        $token = $this->token(new \DateTimeImmutable('+1 hour'));
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token, claims: ['scopes' => ['video:stream']]);

        $claims = $this->claims($jwt);
        self::assertSame(['video:stream'], $claims['scopes']);
        self::assertSame($token->getTokenId(), $claims['jti']);
        self::assertSame($token->getExpiryDateTime()->getTimestamp(), $claims['exp']);
    }

    public function testARowExpiredInThePastYieldsAnExpiredJwt(): void
    {
        $token = $this->token(new \DateTimeImmutable('-1 hour'));
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);

        $exp = $this->claims($jwt)['exp'];
        self::assertIsInt($exp);
        self::assertLessThan(time(), $exp);
        self::assertSame($token->getExpiryDateTime()->getTimestamp(), $exp);
    }

    public function testBindingAFingerprintPersistsMetadataForTheToken(): void
    {
        $token = $this->token(new \DateTimeImmutable('+1 hour'));
        $manager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $manager->expects(self::once())->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });
        $manager->expects(self::once())->method('flush');

        $metadata = OAuthAccessTokenJwt::bindFingerprint($manager, $token, 'fingerprint-of-the-client');

        self::assertSame($persisted, $metadata);
        self::assertInstanceOf(TokenMetadataEntity::class, $metadata);
        self::assertSame($token, $metadata->getToken());
        self::assertSame('fingerprint-of-the-client', $metadata->getClientFingerprint());
    }

    private function token(\DateTimeImmutable $expiresAt): AccessTokenEntity
    {
        $client = new ClientEntity(new PublicId(), 'Disposable client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $user = new UserEntity(new PublicId(), 'Actor', 'actor@baander.app', 'test-only-password-hash', 'test-only-totp-secret');
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $user, scopes: ['library'], expiresAt: $expiresAt);
        $token->setDpopJkt('thumbprint-of-the-row');

        return $token;
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);
        $claims = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        return $claims;
    }

    private function signatureVerifies(string $jwt): bool
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decoded = base64_decode(strtr($signature, '-_', '+/'), true);
        self::assertIsString($decoded);

        return openssl_verify("$header.$payload", $decoded, self::$publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
