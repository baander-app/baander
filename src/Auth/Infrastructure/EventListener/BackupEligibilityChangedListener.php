<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webauthn\Event\BackupEligibilityChangedEvent;

#[AsEventListener]
final class BackupEligibilityChangedListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BackupEligibilityChangedEvent $event): void
    {
        $credentialId = base64_encode($event->credentialRecord->publicKeyCredentialId);

        $this->logger->info('WebAuthn backup eligibility changed.', [
            'credential_id' => $credentialId,
            'user_id' => $event->credentialRecord->userHandle,
            'previous_value' => $event->previousValue,
            'new_value' => $event->newValue,
        ]);
    }
}
