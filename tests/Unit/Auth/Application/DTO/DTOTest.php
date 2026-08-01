<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\DTO;

use App\Auth\Application\DTO\LoginUserDTO;
use App\Auth\Application\DTO\RegisterUserDTO;
use App\Auth\Application\DTO\RequestPasswordResetDTO;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Shared\Domain\Model\Email;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DTOTest extends TestCase
{
    public function testTokenResponseDTOGetters(): void
    {
        $dto = new TokenResponseDTO(
            accessToken: 'tok-123',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'ref-456',
            scopes: ['access-api', 'admin'],
            deviceId: 'device-1',
            verificationInterval: 10,
        );

        $this->assertSame('tok-123', $dto->getAccessToken());
        $this->assertSame('Bearer', $dto->getTokenType());
        $this->assertSame(3600, $dto->getExpiresIn());
        $this->assertSame('ref-456', $dto->getRefreshToken());
        $this->assertSame(['access-api', 'admin'], $dto->getScopes());
        $this->assertSame('device-1', $dto->getDeviceId());
        $this->assertSame(10, $dto->getVerificationInterval());
    }

    public function testTokenResponseDTOToArrayMinimal(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok');

        $array = $dto->toArray();

        $this->assertSame(['access_token' => 'tok', 'token_type' => 'DPoP', 'expires_in' => 3600], $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertArrayNotHasKey('scope', $array);
    }

    public function testTokenResponseDTOToArrayWithRefreshToken(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok', refreshToken: 'ref');

        $array = $dto->toArray();

        $this->assertArrayHasKey('refresh_token', $array);
        $this->assertSame('ref', $array['refresh_token']);
    }

    public function testTokenResponseDTOToArrayWithScopes(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok', scopes: ['profile', 'admin']);

        $array = $dto->toArray();

        $this->assertSame('profile admin', $array['scope']);
    }

    public function testRegisterUserDTOValid(): void
    {
        $dto = new RegisterUserDTO('Alice', 'alice@example.com', 'password123');

        $this->assertSame('Alice', $dto->getName());
        $this->assertSame('alice@example.com', $dto->getEmail()->toString());
        $this->assertSame('password123', $dto->getPassword());
    }

    public function testRegisterUserDTOEmptyNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RegisterUserDTO('  ', 'a@b.com', 'password123');
    }

    public function testRegisterUserDTOShortPasswordThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RegisterUserDTO('Alice', 'a@b.com', 'short');
    }

    public function testLoginUserDTO(): void
    {
        $dto = new LoginUserDTO(new Email('a@b.com'), 'pw');

        $this->assertSame('a@b.com', $dto->getEmail()->toString());
        $this->assertSame('pw', $dto->getPassword());
    }

    public function testRequestPasswordResetDTO(): void
    {
        $dto = new RequestPasswordResetDTO(new Email('a@b.com'));

        $this->assertSame('a@b.com', $dto->getEmail()->toString());
    }
}
