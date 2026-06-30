<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webauthn\Event\BackupStatusChangedEvent;

#[AsEventListener]
final class BackupStatusChangedListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BackupStatusChangedEvent $event): void
    {
        $credentialId = base64_encode($event->credentialRecord->publicKeyCredentialId);

        $this->logger->info('WebAuthn backup status changed.', [
            'credential_id' => $credentialId,
            'user_id' => $event->credentialRecord->userHandle,
            'previous_value' => $event->previousValue,
            'new_value' => $event->newValue,
        ]);
    }
}
