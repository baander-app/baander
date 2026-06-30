<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Service;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenChainValidatorTest extends TestCase
{
    private AccessTokenRepositoryInterface&MockObject $accessTokenRepo;
    private RefreshTokenRepositoryInterface&MockObject $refreshTokenRepo;
    private TokenChainValidator $validator;
    private ChainId $chainId;

    protected function setUp(): void
    {
        $this->accessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->validator = new TokenChainValidator($this->accessTokenRepo, $this->refreshTokenRepo);
        $this->chainId = ChainId::generate();
    }

    private function createRefreshToken(bool $used = false, bool $revoked = false, ?ChainId $chainId = null): RefreshToken
    {
        $client = Client::create('Test', []);
        $accessToken = AccessToken::issue($client, chainId: $chainId ?? $this->chainId);
        $token = RefreshToken::issue($accessToken, $chainId ?? $this->chainId);

        if ($used) {
            $token->markUsed();
        }
        if ($revoked) {
            $token->revoke();
        }

        return $token;
    }

    public function testValidateFirstTokenNoPrevious(): void
    {
        $token = $this->createRefreshToken();

        $this->validator->validate($token, null);

        $this->assertTrue(true);
    }

    public function testValidateReplayRevokesChain(): void
    {
        $token = $this->createRefreshToken(used: true);

        $this->accessTokenRepo->expects($this->once())->method('revokeByChainId');
        $this->refreshTokenRepo->expects($this->once())->method('revokeByChainId');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reuse detected');

        $this->validator->validate($token, null);
    }

    public function testValidatePreviousNotUsedThrows(): void
    {
        $token = $this->createRefreshToken();
        $previous = $this->createRefreshToken(used: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been rotated');

        $this->validator->validate($token, $previous);
    }

    public function testValidatePreviousRevokedThrows(): void
    {
        $previous = $this->createRefreshToken(used: true, revoked: true);
        $token = $this->createRefreshToken();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('previous refresh token has been revoked');

        $this->validator->validate($token, $previous);
    }

    public function testValidateChainIdMismatchThrows(): void
    {
        $previous = $this->createRefreshToken(used: true);
        $token = $this->createRefreshToken(chainId: ChainId::generate());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chain ID mismatch');

        $this->validator->validate($token, $previous);
    }

    public function testValidateWithLoadedPrevious(): void
    {
        $previous = $this->createRefreshToken(used: true);
        $token = RefreshToken::issue(
            $previous->getAccessToken(),
            $this->chainId,
            previousRefreshToken: $previous,
        );

        $this->validator->validateWithLoadedPrevious($token);

        $this->assertTrue(true);
    }

    public function testRevokeChain(): void
    {
        $this->accessTokenRepo->expects($this->once())->method('revokeByChainId')->with($this->chainId);
        $this->refreshTokenRepo->expects($this->once())->method('revokeByChainId')->with($this->chainId);

        $this->validator->revokeChain($this->chainId);
    }
}
