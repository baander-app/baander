<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\EventListener;

use App\Auth\Infrastructure\EventListener\BackupEligibilityChangedListener;
use App\Auth\Infrastructure\EventListener\BackupStatusChangedListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\Event\BackupEligibilityChangedEvent;
use Webauthn\Event\BackupStatusChangedEvent;
use Webauthn\TrustPath\EmptyTrustPath;

final class BackupEventListenersTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private CredentialRecord $credentialRecord;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->credentialRecord = new CredentialRecord(
            publicKeyCredentialId: 'test-cred-id',
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'pub-key',
            userHandle: 'user-handle-123',
            counter: 1,
        );
    }

    public function testBackupEligibilityChangedLogsCorrectData(): void
    {
        $event = new BackupEligibilityChangedEvent(
            $this->credentialRecord,
            previousValue: false,
            newValue: true,
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'WebAuthn backup eligibility changed.',
                $this->callback(function (array $context): bool {
                    return $context['credential_id'] === base64_encode('test-cred-id')
                        && $context['user_id'] === 'user-handle-123'
                        && $context['previous_value'] === false
                        && $context['new_value'] === true;
                }),
            );

        $listener = new BackupEligibilityChangedListener($this->logger);
        $listener($event);
    }

    public function testBackupEligibilityChangedHandlesNullValues(): void
    {
        $event = new BackupEligibilityChangedEvent(
            $this->credentialRecord,
            previousValue: null,
            newValue: true,
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(fn (array $ctx): bool => $ctx['previous_value'] === null && $ctx['new_value'] === true),
            );

        $listener = new BackupEligibilityChangedListener($this->logger);
        $listener($event);
    }

    public function testBackupStatusChangedLogsCorrectData(): void
    {
        $event = new BackupStatusChangedEvent(
            $this->credentialRecord,
            previousValue: true,
            newValue: false,
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'WebAuthn backup status changed.',
                $this->callback(function (array $context): bool {
                    return $context['credential_id'] === base64_encode('test-cred-id')
                        && $context['user_id'] === 'user-handle-123'
                        && $context['previous_value'] === true
                        && $context['new_value'] === false;
                }),
            );

        $listener = new BackupStatusChangedListener($this->logger);
        $listener($event);
    }

    public function testBackupStatusChangedHandlesNullValues(): void
    {
        $event = new BackupStatusChangedEvent(
            $this->credentialRecord,
            previousValue: null,
            newValue: false,
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(fn (array $ctx): bool => $ctx['previous_value'] === null && $ctx['new_value'] === false),
            );

        $listener = new BackupStatusChangedListener($this->logger);
        $listener($event);
    }
}
