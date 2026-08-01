<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Auth\Domain\Event\UserRegistered;
use App\Shared\Application\Port\AdminAlertPortInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listens for domain events that should generate admin alerts.
 *
 * Admin alerts bypass the normal notification pipeline — they directly
 * create AdminOperations notifications for all admin users.
 */
final class AdminAlertSubscriber
{
    public function __construct(
        private readonly AdminAlertPortInterface $adminAlertPort,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onUserRegistered(UserRegistered $event): void
    {
        try {
            $payload = $event->toPayload();
            $name = $payload['name'] ?? 'Unknown user';

            $this->adminAlertPort->alertAdmins(
                title: 'New user registered',
                body: "{$name} has created an account.",
                eventType: 'admin.user_registered',
                referenceData: ['user_id' => $payload['user_id'] ?? null],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send admin alert for user registration: {error}', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
