<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SendWebhookHandler
{
    public function __construct(
        private readonly WebhookDeliveryService $deliveryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SendWebhookCommand $command): void
    {
        try {
            $this->deliveryService->deliverAll(
                title: $command->title,
                body: $command->body,
                category: $command->category,
                notificationId: $command->notificationPublicId,
                userId: $command->userId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Webhook dispatch failed.', [
                'channel' => 'notification.webhook',
                'notification_id' => $command->notificationPublicId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
