<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

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
        $accepted = (new ReflectionClassConstant(DpopProofValidator::class, 'SUPPORTED_ALGORITHMS'))->getValue();

        self::assertSame($accepted, AuthorizationServerMetadataController::DPOP_SIGNING_ALGORITHMS);
    }

    public function testEndpointsAreRootedAtTheIssuer(): void
    {
        $paths = [
            'oauth_authorize' => '/api/oauth/authorize',
            'oauth_token' => '/api/oauth/token',
            'oauth_revoke' => '/api/oauth/revoke',
            'oauth_device_authorize' => '/api/oauth/device/authorize',
            'jwks' => '/.well-known/jwks.json',
        ];
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => $paths[$route]);

        $response = (new AuthorizationServerMetadataController($router, 'https://music.baander.app/', ['profile', 'library']))();
        $metadata = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://music.baander.app/', $metadata['issuer']);
        self::assertSame('https://music.baander.app/api/oauth/token', $metadata['token_endpoint']);
        self::assertSame('https://music.baander.app/api/oauth/device/authorize', $metadata['device_authorization_endpoint']);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertSame(['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'], $metadata['grant_types_supported']);
        self::assertSame(['profile', 'library'], $metadata['scopes_supported']);
        self::assertArrayNotHasKey('introspection_endpoint', $metadata);
    }
}
