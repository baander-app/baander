<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Infrastructure\Security\OAuth\DpopAwareBearerTokenValidator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenAudienceTest extends TestCase
{
    #[DataProvider('audiences')]
    public function testRequiredAudience(mixed $audience, bool $accepted): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $claims = [
            'jti' => 'token-id', 'sub' => 'user-id', 'client_id' => 'actual-client',
            'scopes' => ['profile'], 'iat' => time(), 'nbf' => time() - 1, 'exp' => time() + 60,
        ];
        if ($audience !== null) {
            $claims['aud'] = $audience;
        }
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $body = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$encode(json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256));
        $request = new ServerRequest('GET', 'https://resource.example/api/test', [
            'Authorization' => 'Bearer '.$body.'.'.$encode($signature),
        ]);
        // A PSR request attribute must never override the authenticated header.
        $request = $request->withAttribute('Authorization', 'forged-attribute');
        $repository = $this->createStub(AccessTokenRepositoryInterface::class);
        $repository->method('isAccessTokenRevoked')->willReturn(false);
        $validator = new DpopAwareBearerTokenValidator($repository, null, 'https://resource.example');
        $validator->setPublicKey(new CryptKey($details['key'], null, false));

        if (!$accepted) {
            $this->expectException(OAuthServerException::class);
        }
        $validated = $validator->validateAuthorization($request);
        self::assertSame('actual-client', $validated->getAttribute('oauth_client_id'));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function audiences(): iterable
    {
        yield 'string' => ['https://resource.example', true];
        yield 'array' => [['https://other.example', 'https://resource.example'], true];
        yield 'wrong string' => ['https://other.example', false];
        yield 'wrong array' => [['https://other.example'], false];
        yield 'missing' => [null, false];
        yield 'empty array' => [[], false];
        yield 'empty string' => ['', false];
        yield 'malformed list' => [['https://resource.example', 42], false];
        yield 'object' => [['resource' => 'https://resource.example'], false];
    }
}
