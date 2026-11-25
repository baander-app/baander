<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Infrastructure\Security\OAuth\ClientAssertionVerifier;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Interface\Controller\OAuth\AuthorizationServerMetadataController;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** RFC 8414 metadata advertises exactly what the server implements. */
final class AuthorizationServerMetadataTest extends TestCase
{
    public function testAdvertisedDpopAlgorithmsAreThoseTheProofValidatorAccepts(): void
    {
        $accepted = new ReflectionClassConstant(DpopProofValidator::class, 'SUPPORTED_ALGORITHMS')->getValue();

        self::assertSame($accepted, AuthorizationServerMetadataController::DPOP_SIGNING_ALGORITHMS);
    }

    public function testAdvertisedClientAssertionAlgorithmsAreThoseTheVerifierAccepts(): void
    {
        self::assertSame(ClientAssertionVerifier::SUPPORTED_ALGORITHMS, AuthorizationServerMetadataController::CLIENT_ASSERTION_SIGNING_ALGORITHMS);
    }

    /** A proof is signed with an EC or Ed25519 key; a client assertion may also come from an RSA key. */
    public function testProofsAreAdvertisedWithoutRsaAlgorithmsAndAssertionsWithThem(): void
    {
        $metadata = $this->metadata();

        self::assertSame(['EdDSA', 'ES256', 'ES384', 'ES512'], $metadata['dpop_signing_alg_values_supported']);
        self::assertSame(
            ['EdDSA', 'ES256', 'ES384', 'ES512', 'PS256', 'RS256', 'RS384', 'RS512'],
            $metadata['token_endpoint_auth_signing_alg_values_supported'],
        );
    }

    public function testEndpointsAreRootedAtTheIssuer(): void
    {
        $metadata = $this->metadata();

        self::assertSame('https://music.baander.app/', $metadata['issuer']);
        self::assertSame('https://music.baander.app/oauth/authorize', $metadata['authorization_endpoint'], 'The web app consent page.');
        self::assertSame('https://music.baander.app/api/oauth/token', $metadata['token_endpoint']);
        self::assertSame('https://music.baander.app/api/oauth/device/authorize', $metadata['device_authorization_endpoint']);
        self::assertSame('https://music.baander.app/api/oauth/par', $metadata['pushed_authorization_request_endpoint']);
        self::assertTrue($metadata['require_pushed_authorization_requests'], 'Every authorization request is pushed first.');
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertSame(['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'], $metadata['grant_types_supported']);
        self::assertSame(['profile', 'library'], $metadata['scopes_supported']);
        self::assertSame(['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'], $metadata['token_endpoint_auth_methods_supported']);
        self::assertSame(AuthorizationServerMetadataController::CLIENT_ASSERTION_SIGNING_ALGORITHMS, $metadata['token_endpoint_auth_signing_alg_values_supported']);
        self::assertArrayNotHasKey('introspection_endpoint', $metadata);
        self::assertArrayNotHasKey('revocation_endpoint', $metadata, 'The revocation route authenticates the user, not the client (RFC 7009 section 2.1).');
    }

    /** @return array<string, mixed> The document published for the issuer `https://music.baander.app/` */
    private function metadata(): array
    {
        $paths = [
            'oauth_token' => '/api/oauth/token',
            'oauth_par' => '/api/oauth/par',
            'oauth_revoke' => '/api/oauth/revoke',
            'oauth_device_authorize' => '/api/oauth/device/authorize',
            'jwks' => '/.well-known/jwks.json',
        ];
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => $paths[$route]);

        $response = (new AuthorizationServerMetadataController($router, 'https://music.baander.app/', 'https://music.baander.app/oauth/authorize', ['profile', 'library']))();

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
