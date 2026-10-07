<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class CommandsTest extends TestCase
{
    public function testRegisterUserCommandGetters(): void
    {
        $cmd = new RegisterUserCommand(new Email('a@b.com'), 'Alice', 'pw');

        $this->assertSame('a@b.com', $cmd->getEmail()->toString());
        $this->assertSame('Alice', $cmd->getName());
        $this->assertSame('pw', $cmd->getPlainPassword());
    }

    public function testRefreshTokenCommandGetters(): void
    {
        $cmd = new RefreshTokenCommand('ref-id', '1.2.3.4', 'Agent');

        $this->assertSame('ref-id', $cmd->getRefreshTokenId());
        $this->assertSame('1.2.3.4', $cmd->getIpAddress());
        $this->assertSame('Agent', $cmd->getUserAgent());
    }

    public function testRevokeTokenCommandGetters(): void
    {
        $cmd = new RevokeTokenCommand('tok-id', true);

        $this->assertSame('tok-id', $cmd->getTokenId());
        $this->assertTrue($cmd->shouldRevokeChain());
    }

    public function testIssueTokenCommandGetters(): void
    {
        $clientId = Uuid::v4();
        $userId = Uuid::v4();
        $cmd = new IssueTokenCommand(
            clientId: $clientId,
            userId: $userId,
            dpopJkt: 'thumbprint',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            clientFingerprint: 'fingerprint',
        );

        $this->assertSame($clientId, $cmd->getClientId());
        $this->assertSame($userId, $cmd->getUserId());
        $this->assertSame('thumbprint', $cmd->getDpopJkt());
        $this->assertSame('127.0.0.1', $cmd->getIpAddress());
        $this->assertSame('PHPUnit', $cmd->getUserAgent());
        $this->assertSame('fingerprint', $cmd->getClientFingerprint());
    }

    public function testIssueTokenCommandDefaults(): void
    {
        $cmd = new IssueTokenCommand(Uuid::v4(), Uuid::v4(), 'thumbprint');

        $this->assertEmpty($cmd->getScopes());
        $this->assertNull($cmd->getTokenName());
        $this->assertNull($cmd->getClientFingerprint());
    }

    public function testAuthenticatePasskeyCommandGetters(): void
    {
        $response = ['id' => 'raw-id-value', 'clientDataJSON' => 'cdj', 'authenticatorData' => 'ad'];
        $cmd = new AuthenticatePasskeyCommand('user-uuid', 'challenge-key', $response);

        $this->assertSame('user-uuid', $cmd->getUserId());
        $this->assertSame('challenge-key', $cmd->getChallengeKey());
        $this->assertSame($response, $cmd->getResponse());
    }

    public function testAuthenticatePasskeyCommandNullUserId(): void
    {
        $cmd = new AuthenticatePasskeyCommand(null, 'challenge-key', ['id' => 'raw-id']);

        $this->assertNull($cmd->getUserId());
    }

    public function testRegisterPasskeyCommandGetters(): void
    {
        $cmd = new RegisterPasskeyCommand('user-1', 'My Key', 'cred-id', ['data'], 0);

        $this->assertSame('user-1', $cmd->getUserId());
        $this->assertSame('My Key', $cmd->getName());
        $this->assertSame('cred-id', $cmd->getCredentialId());
        $this->assertSame(['data'], $cmd->getCredentialRecordData());
        $this->assertSame(0, $cmd->getCounter());
    }

    public function testEnableTotpCommandGetters(): void
    {
        $cmd = new EnableTotpCommand('user-1', '123456');

        $this->assertSame('user-1', $cmd->getUserId());
        $this->assertSame('123456', $cmd->getCode());
    }

    public function testRequestPasswordResetCommandGetters(): void
    {
        $cmd = new RequestPasswordResetCommand(new Email('a@b.com'));

        $this->assertSame('a@b.com', $cmd->getEmail()->toString());
    }
}
