<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Shared\Infrastructure\Swoole\Async;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookDeliveryLogEntity;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookDeliveryService
{
    private const MAX_RETRIES = 3;

    private const BACKOFF_DELAYS = [1, 2, 4];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly HmacSigner $hmacSigner,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly WebhookDestinationPolicy $destinations = new WebhookDestinationPolicy(),
        private readonly ?WebhookSecretPortInterface $secrets = null,
    ) {
    }

    public function deliverAll(
        string $title,
        string $body,
        NotificationCategory $category,
        string $notificationId,
        Uuid $userId,
    ): void {
        $webhooks = $this->loadWebhooks();

        foreach ($webhooks as $webhook) {
            if (!$this->matchesCategoryFilter($webhook, $category)) {
                continue;
            }

            $this->deliver($webhook, $title, $body, $category, $notificationId);
        }
    }

    /**
     * @return list<WebhookEntity>
     */
    private function loadWebhooks(): array
    {
        return $this->entityManager
            ->getRepository(WebhookEntity::class)
            ->findAll();
    }

    private function matchesCategoryFilter(WebhookEntity $webhook, NotificationCategory $category): bool
    {
        $filter = $webhook->getCategoryFilter();
        if ($filter === null) {
            return true;
        }

        return in_array($category->value, $filter, true);
    }

    private function deliver(
        WebhookEntity $webhook,
        string $title,
        string $body,
        NotificationCategory $category,
        string $notificationId,
    ): void {
        $url = $webhook->getUrl();
        $safe = $this->destinations->resolve($url);

        if ($safe === null) {
            $this->logger->warning('Webhook blocked: URL failed SSRF validation.', [
                'channel' => 'notification.webhook',
                'webhook_id' => $webhook->getId()->toString(),
                'url' => $url,
            ]);

            return;
        }

        $payload = $this->jsonEncoder->encode([
            'title' => $title,
            'body' => $body,
            'category' => $category->value,
            'notification_id' => $notificationId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], 'json');

        // Pin the validated IP so the client connects only to an address already
        // checked, closing the DNS-rebinding window between validation and the
        // connection (and across retries).
        $resolve = [$safe['host'] => $safe['ips'][0]];

        $lastStatusCode = null;

        try {
            $signingSecret = match ($webhook->getSigningVersion()) {
                1 => $webhook->getSecretHash(),
                2 => $this->secrets?->decrypt($webhook->getEncryptedSecret() ?? '')
                    ?? throw new \RuntimeException('Webhook secret encryption is not configured.'),
                default => throw new \RuntimeException('Unsupported webhook signature version.'),
            };
        } catch (\Throwable) {
            $this->logger->error('Webhook signing secret could not be decrypted.', ['webhook_id' => $webhook->getId()->toString()]);
            $this->logDelivery($webhook, $notificationId, 'failed', null, 0);
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            // Re-sign per attempt: a fresh timestamp keeps retries valid against
            // receivers that enforce signature freshness.
            $timestamp = (string) time();
            $signature = $this->hmacSigner->sign($timestamp . '.' . $payload, $signingSecret);

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Webhook-Signature' => $signature,
                        'X-Webhook-Signature-Version' => (string) $webhook->getSigningVersion(),
                        'X-Webhook-Timestamp' => $timestamp,
                        'User-Agent' => 'Baander-Webhook/1.0',
                    ],
                    'body' => $payload,
                    'timeout' => 10,
                    'resolve' => $resolve,
                    'max_redirects' => 0,
                    'proxy' => '',
                ]);

                $statusCode = $response->getStatusCode();
                $lastStatusCode = $statusCode;

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logDelivery($webhook, $notificationId, 'success', $statusCode, $attempt);

                    return;
                }

                $this->logger->warning('Webhook delivery returned non-success status.', [
                    'channel' => 'notification.webhook',
                    'webhook_id' => $webhook->getId()->toString(),
                    'notification_id' => $notificationId,
                    'status_code' => $statusCode,
                    'attempt' => $attempt,
                ]);

                if ($statusCode >= 300 && $statusCode < 500) {
                    $this->logDelivery($webhook, $notificationId, 'failed', $statusCode, $attempt);

                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Webhook delivery failed.', [
                    'channel' => 'notification.webhook',
                    'webhook_id' => $webhook->getId()->toString(),
                    'notification_id' => $notificationId,
                    'attempt' => $attempt,
                    'exception' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::MAX_RETRIES) {
                $delay = self::BACKOFF_DELAYS[$attempt - 1] ?? 1;
                Async::sleep($delay);
            }
        }

        // Retries exhausted — log the last known status code. It is null only when
        // every attempt threw before receiving a response (e.g. network errors).
        $this->logDelivery($webhook, $notificationId, 'failed', $lastStatusCode, self::MAX_RETRIES);
    }

    private function logDelivery(
        WebhookEntity $webhook,
        string $notificationId,
        string $status,
        ?int $httpStatusCode,
        int $attempt,
    ): void {
        $logEntity = new WebhookDeliveryLogEntity(Uuid::generate());
        $logEntity->setWebhook($webhook);
        $logEntity->setNotificationId($notificationId);
        $logEntity->setStatus($status);
        $logEntity->setHttpStatusCode($httpStatusCode);
        $logEntity->setAttempt($attempt);

        try {
            $this->entityManager->persist($logEntity);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist webhook delivery log.', [
                'channel' => 'notification.webhook',
                'webhook_id' => $webhook->getId()->toString(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function isUrlSafe(string $url): bool
    {
        return $this->destinations->resolve($url) !== null;
    }

}
