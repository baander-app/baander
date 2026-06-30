<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Resource;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Interface\Resource\TokenResource;
use PHPUnit\Framework\TestCase;

final class TokenResourceTest extends TestCase
{
    // --- from() tests (TokenResponseDTO source) ---

    public function testFromWithFullTokenResponse(): void
    {
        $dto = new TokenResponseDTO(
            accessToken: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'def50200...',
        );

        $result = TokenResource::from($dto);

        $this->assertSame([
            'accessToken' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9',
            'tokenType' => 'Bearer',
            'expiresIn' => 3600,
            'refreshToken' => 'def50200...',
        ], $result);
    }

    public function testFromWithMinimalTokenResponse(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok_minimal');

        $result = TokenResource::from($dto);

        $this->assertSame('tok_minimal', $result['accessToken']);
        $this->assertSame('DPoP', $result['tokenType']);
        $this->assertSame(3600, $result['expiresIn']);
        $this->assertNull($result['refreshToken']);
    }

    public function testFromWithScopesDoesNotIncludeScopes(): void
    {
        $dto = new TokenResponseDTO(
            accessToken: 'tok_scoped',
            scopes: ['profile', 'email'],
        );

        $result = TokenResource::from($dto);

        // Scopes are not part of the standard token resource shape
        $this->assertArrayNotHasKey('scopes', $result);
        $this->assertArrayNotHasKey('scope', $result);
    }

    // --- fromOAuthResponse() tests (raw League response) ---

    public function testFromOAuthResponseWithFullLeagueResponse(): void
    {
        $leagueResponse = [
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9',
            'refresh_token' => 'def50200...',
        ];

        $result = TokenResource::fromOAuthResponse($leagueResponse);

        $this->assertSame([
            'accessToken' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9',
            'tokenType' => 'Bearer',
            'expiresIn' => 3600,
            'refreshToken' => 'def50200...',
        ], $result);
    }

    public function testFromOAuthResponseWithoutRefreshToken(): void
    {
        $leagueResponse = [
            'token_type' => 'Bearer',
            'expires_in' => 7200,
            'access_token' => 'tok_no_refresh',
        ];

        $result = TokenResource::fromOAuthResponse($leagueResponse);

        $this->assertNull($result['refreshToken']);
    }

    public function testFromOAuthResponseWithExtraFieldsIgnoresUnknownKeys(): void
    {
        $leagueResponse = [
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'tok_extra',
            'refresh_token' => 'ref_extra',
            'scope' => 'profile email',
        ];

        $result = TokenResource::fromOAuthResponse($leagueResponse);

        // Only the four standard fields should be present
        $this->assertSame([
            'accessToken' => 'tok_extra',
            'tokenType' => 'Bearer',
            'expiresIn' => 3600,
            'refreshToken' => 'ref_extra',
        ], $result);
    }

    public function testFromOAuthResponseDefaultsOnMissingFields(): void
    {
        $result = TokenResource::fromOAuthResponse([]);

        $this->assertSame('', $result['accessToken']);
        $this->assertSame('Bearer', $result['tokenType']);
        $this->assertSame(0, $result['expiresIn']);
        $this->assertNull($result['refreshToken']);
    }

    // --- Shape parity: from() and fromOAuthResponse() produce identical keys ---

    public function testFromAndFromOAuthResponseProduceIdenticalShape(): void
    {
        $dto = new TokenResponseDTO(
            accessToken: 'tok_abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'ref_abc',
        );

        $fromResult = TokenResource::from($dto);

        $leagueResponse = [
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'tok_abc',
            'refresh_token' => 'ref_abc',
        ];
        $fromOAuthResult = TokenResource::fromOAuthResponse($leagueResponse);

        // Both methods must produce arrays with the exact same keys
        $this->assertSame(array_keys($fromResult), array_keys($fromOAuthResult));
        $this->assertSame($fromResult, $fromOAuthResult);
    }

    // --- Backward compatibility ---

    public function testFromDtoBackwardCompat(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok_compat');

        $result = TokenResource::fromDto($dto);

        $this->assertSame('tok_compat', $result['accessToken']);
    }
}
