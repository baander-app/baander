<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\Passkey\AuthenticatorCounterChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

final class AuthenticatorCounterCheckerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private AuthenticatorCounterChecker $checker;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->checker = new AuthenticatorCounterChecker($this->logger);
    }

    private function createCredentialRecord(int $counter): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: 'cred-id-bytes',
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'pub-key-bytes',
            userHandle: 'user-handle',
            counter: $counter,
        );
    }

    public function testCheckPassesWhenCounterIncreases(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $credential = $this->createCredentialRecord(5);

        $this->checker->check($credential, 6);
    }

    public function testCheckPassesWhenCounterJumps(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $credential = $this->createCredentialRecord(0);

        $this->checker->check($credential, 100);

        $this->assertTrue(true);
    }

    public function testCheckFailsWhenCounterIsEqual(): void
    {
        $credential = $this->createCredentialRecord(5);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Cloned authenticator detected: assertion counter not greater than stored counter.',
                $this->callback(function (array $context): bool {
                    return $context['stored_counter'] === 5
                        && $context['assertion_counter'] === 5
                        && isset($context['credential_id'])
                        && $context['user_id'] === 'user-handle';
                }),
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authenticator counter check failed');

        $this->checker->check($credential, 5);
    }

    public function testCheckFailsWhenCounterDecreases(): void
    {
        $credential = $this->createCredentialRecord(10);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Cloned authenticator detected: assertion counter not greater than stored counter.',
                $this->callback(function (array $context): bool {
                    return $context['stored_counter'] === 10
                        && $context['assertion_counter'] === 3;
                }),
            );

        $this->expectException(RuntimeException::class);

        $this->checker->check($credential, 3);
    }

    public function testCheckLogsCredentialIdAsBase64(): void
    {
        $rawId = 'binary-credential-id';
        $credential = $this->createCredentialRecord(5);
        $credential->publicKeyCredentialId = $rawId;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(fn (array $ctx): bool => $ctx['credential_id'] === base64_encode($rawId)),
            );

        $this->expectException(RuntimeException::class);

        $this->checker->check($credential, 5);
    }
}
