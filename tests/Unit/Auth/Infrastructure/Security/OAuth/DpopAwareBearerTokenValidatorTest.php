<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\OAuth\DpopAwareBearerTokenValidator;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Nyholm\Psr7\ServerRequest;
use League\OAuth2\Server\CryptKey;
use PHPUnit\Framework\TestCase;

final class DpopAwareBearerTokenValidatorTest extends TestCase
{
    private AccessTokenRepositoryInterface $accessTokenRepository;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->accessTokenRepository->method('isAccessTokenRevoked')->willReturn(false);
    }

    public function testValidatorRejectsTokenWithMismatchedAudience(): void
    {
        [$request, $publicKey] = $this->signedRequest('https://wrong-resource-server.com');
        $matching = new DpopAwareBearerTokenValidator($this->accessTokenRepository, null, 'https://wrong-resource-server.com');
        $matching->setPublicKey($publicKey);
        self::assertSame('user-uuid', $matching->validateAuthorization($request)->getAttribute('oauth_user_id'));

        $validator = new DpopAwareBearerTokenValidator($this->accessTokenRepository, null, 'https://baander.example.com');
        $validator->setPublicKey($publicKey);
        $this->expectException(OAuthServerException::class);
        $validator->validateAuthorization($request);
    }

    public function testValidatorParsesClientIdFromJwt(): void
    {
        [$request, $publicKey] = $this->signedRequest('https://baander.example.com');
        $validator = new DpopAwareBearerTokenValidator($this->accessTokenRepository, null, 'https://baander.example.com');
        $validator->setPublicKey($publicKey);
        $validated = $validator->validateAuthorization($request);
        self::assertSame('client-uuid', $validated->getAttribute('oauth_client_id'));
        self::assertSame('user-uuid', $validated->getAttribute('oauth_user_id'));
    }

    public function testValidatorWorksWithoutResourceServerUri(): void
    {
        [$request, $publicKey] = $this->signedRequest('https://baander.example.com');
        $validator = new DpopAwareBearerTokenValidator($this->accessTokenRepository);
        $validator->setPublicKey($publicKey);
        self::assertSame('client-uuid', $validator->validateAuthorization($request)->getAttribute('oauth_client_id'));
    }

    /** @return array{ServerRequest, CryptKey} */
    private function signedRequest(string $audience): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $claims = [
            'jti' => 'test-token-id', 'sub' => 'user-uuid', 'aud' => $audience,
            'client_id' => 'client-uuid', 'scopes' => ['profile'],
            'iat' => time(), 'nbf' => time() - 1, 'exp' => time() + 60,
        ];
        $body = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . $encode(json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256));
        return [
            new ServerRequest('GET', 'https://baander.example.com/api/test', ['Authorization' => 'Bearer ' . $body . '.' . $encode($signature)]),
            new CryptKey($details['key'], null, false),
        ];
    }

    public function testParseJwtClaimsExtractsClientIdAndAud(): void
    {
        // Use reflection to test the private parseJwtClaims method
        $payload = base64_encode(json_encode([
            'aud' => 'https://baander.example.com',
            'client_id' => 'client-uuid-123',
            'sub' => 'user-uuid-456',
        ]));

        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $signature = base64_encode('fake-signature');
        $jwt = implode('.', [$header, $payload, $signature]);

        // Create validator and use reflection to access private method
        $validator = new DpopAwareBearerTokenValidator(
            $this->accessTokenRepository,
            null,
            'https://baander.example.com',
        );

        $reflection = new \ReflectionMethod($validator, 'parseJwtClaims');
        $claims = $reflection->invoke($validator, $jwt);

        $this->assertSame('https://baander.example.com', $claims['aud']);
        $this->assertSame('client-uuid-123', $claims['client_id']);
        $this->assertSame('user-uuid-456', $claims['sub']);
    }

    public function testParseJwtClaimsReturnsEmptyForInvalidJwt(): void
    {
        $validator = new DpopAwareBearerTokenValidator(
            $this->accessTokenRepository,
        );

        $reflection = new \ReflectionMethod($validator, 'parseJwtClaims');

        $this->assertEmpty($reflection->invoke($validator, 'not-a-jwt'));
        $this->assertEmpty($reflection->invoke($validator, 'a.b'));
        $this->assertEmpty($reflection->invoke($validator, 'a.b.c'));
    }
}
