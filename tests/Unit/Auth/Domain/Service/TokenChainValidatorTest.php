<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Service;

use App\Auth\Domain\Exception\TokenChainViolationException;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Service\TokenChainValidator;
use PHPUnit\Framework\TestCase;

final class TokenChainValidatorTest extends TestCase
{
    private TokenChainValidator $validator;
    private ChainId $chainId;

    protected function setUp(): void
    {
        $this->validator = new TokenChainValidator();
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

        $this->assertFalse($token->isRevoked());
    }

    /** A used token is refused; the refresh handler decides under the row lock whether the chain ends. */
    public function testValidateRejectsAUsedTokenWithoutRevoking(): void
    {
        $token = $this->createRefreshToken(used: true);

        $this->expectException(TokenChainViolationException::class);
        $this->expectExceptionMessage('reuse detected');

        $this->validator->validate($token, null);
    }

    public function testValidatePreviousNotUsedThrows(): void
    {
        $token = $this->createRefreshToken();
        $previous = $this->createRefreshToken(used: false);

        $this->expectException(TokenChainViolationException::class);
        $this->expectExceptionMessage('not been rotated');

        $this->validator->validate($token, $previous);
    }

    public function testValidatePreviousRevokedThrows(): void
    {
        $previous = $this->createRefreshToken(used: true, revoked: true);
        $token = $this->createRefreshToken();

        $this->expectException(TokenChainViolationException::class);
        $this->expectExceptionMessage('previous refresh token has been revoked');

        $this->validator->validate($token, $previous);
    }

    public function testValidateChainIdMismatchThrows(): void
    {
        $previous = $this->createRefreshToken(used: true);
        $token = $this->createRefreshToken(chainId: ChainId::generate());

        $this->expectException(TokenChainViolationException::class);
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

        $this->assertFalse($token->isRevoked());
    }
}
