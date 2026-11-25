<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\PushSendReport;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\Port\PushDeliveryPortInterface;
use App\Notification\Application\Settings\NotificationSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Sends one notification to one subscription. Messenger retries a failure the push service may
 * recover from (no answer, 408, 429, 5xx) for this subscription alone; 404 and 410 remove the
 * subscription; any other 4xx is permanent.
 */
final class SendPushToSubscriptionHandler
{
    private const array EXPIRED = [404, 410];

    private const array RETRYABLE_CLIENT_ERRORS = [408, 429];

    public function __construct(
        private readonly PushDeliveryPortInterface $delivery,
        private readonly LoggerInterface $logger,
        private readonly string $appDomain,
        private readonly JsonEncoder $jsonEncoder,
        private readonly SystemSettingsPortInterface $systemSettings,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async')]
    public function __invoke(SendPushToSubscriptionCommand $command): void
    {
        // A retry may arrive after the server turned push off.
        if ($this->systemSettings->get(NotificationSettingDefinitions::PUSH_ENABLED) !== true) {
            return;
        }

        // Null: removed meanwhile, by the user or after an earlier expiry answer.
        $report = $this->delivery->send($command->subscriptionId, $command->userId, $this->payload($command));
        if ($report === null || $report->delivered) {
            return;
        }

        if (in_array($report->status, self::EXPIRED, true)) {
            $this->delivery->remove($command->subscriptionId);

            return;
        }

        $this->logger->error('Failed to send push notification.', [
            'channel' => 'notification.push',
            'notification_id' => $command->notificationPublicId,
            'subscription_id' => $command->subscriptionId->toString(),
            'status' => $report->status,
        ]);
        if (self::isPermanent($report)) {
            throw new UnrecoverableMessageHandlingException(sprintf('Push service refused the notification with HTTP status %d.', $report->status));
        }

        throw new \RuntimeException('Push delivery failed for one subscription.');
    }

    /** A 4xx the push service will answer again: the request itself is refused. */
    private static function isPermanent(PushSendReport $report): bool
    {
        return $report->status !== null
            && $report->status >= 400
            && $report->status < 500
            && !in_array($report->status, self::RETRYABLE_CLIENT_ERRORS, true);
    }

    private function payload(SendPushToSubscriptionCommand $command): string
    {
        return $this->jsonEncoder->encode([
            'title' => $command->title,
            'body' => $command->body,
            'icon' => '/icons/notification.png',
            'url' => sprintf('https://%s/notifications/%s', $this->appDomain, $command->notificationPublicId),
        ], 'json');
    }
}
