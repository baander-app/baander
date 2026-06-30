<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook;

use App\Notification\Domain\ValueObject\NotificationCategory;
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

    /**
     * @var list<array{network: string, prefix: int, bits: int}>
     */
    private const IP_BLOCKLIST = [
        ['network' => '10.0.0.0', 'prefix' => 8, 'bits' => 32],
        ['network' => '172.16.0.0', 'prefix' => 12, 'bits' => 32],
        ['network' => '192.168.0.0', 'prefix' => 16, 'bits' => 32],
        ['network' => '127.0.0.0', 'prefix' => 8, 'bits' => 32],
        ['network' => '169.254.0.0', 'prefix' => 32, 'bits' => 32],
        ['network' => '::1', 'prefix' => 128, 'bits' => 128],
        ['network' => 'fc00::', 'prefix' => 7, 'bits' => 128],
        ['network' => 'fe80::', 'prefix' => 10, 'bits' => 128],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly HmacSigner $hmacSigner,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
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

        if (!$this->isUrlSafe($url)) {
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

        $timestamp = (string) time();
        $signature = $this->hmacSigner->sign($timestamp . '.' . $payload, $webhook->getSecretHash());

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Webhook-Signature' => $signature,
                        'X-Webhook-Timestamp' => $timestamp,
                        'User-Agent' => 'Baander-Webhook/1.0',
                    ],
                    'body' => $payload,
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();

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

                if ($statusCode >= 400 && $statusCode < 500) {
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

        $this->logDelivery($webhook, $notificationId, 'failed', null, self::MAX_RETRIES);
    }

    private function logDelivery(
        WebhookEntity $webhook,
        string $notificationId,
        string $status,
        ?int $httpStatusCode,
        int $attempt,
    ): void {
        $logEntity = new WebhookDeliveryLogEntity(Uuid::generate());
        $logEntity->setWebhookId($webhook->getId());
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
        $parsed = parse_url($url);

        if ($parsed === false) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return false;
        }

        $resolvedIps = $this->resolveHost($host);
        if ($resolvedIps === []) {
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if ($this->isPrivateIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            } elseif (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        return $ips;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ipv4 = substr($ip, 7);
            if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $this->isPrivateIp($ipv4);
            }

            return true;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        $isIpv4 = str_contains($ip, '.');
        $bits = $isIpv4 ? 32 : 128;

        foreach (self::IP_BLOCKLIST as $range) {
            if ($range['bits'] !== $bits) {
                continue;
            }

            if ($this->cidrMatch($ip, $range['network'], $range['prefix'], $bits)) {
                return true;
            }
        }

        return false;
    }

    private function cidrMatch(string $ip, string $network, int $prefix, int $bits): bool
    {
        if ($prefix === 0) {
            return true;
        }

        if ($bits === 32) {
            $ipLong = $this->ipToLong($ip);
            $networkLong = $this->ipToLong($network);

            if ($ipLong === null || $networkLong === null) {
                return false;
            }

            $mask = (~0 << (32 - $prefix)) & 0xFFFFFFFF;

            return ($ipLong & $mask) === ($networkLong & $mask);
        }

        $ipPacked = inet_pton($ip);
        $networkPacked = inet_pton($network);

        if ($ipPacked === false || $networkPacked === false) {
            return false;
        }

        $fullBytes = (int) floor($prefix / 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits > 0) {
            $ipByte = ord($ipPacked[$fullBytes]);
            $networkByte = ord($networkPacked[$fullBytes]);
            $bitMask = (0xFF << (8 - $remainingBits)) & 0xFF;

            return ($ipByte & $bitMask) === ($networkByte & $bitMask);
        }

        return true;
    }

    private function ipToLong(string $ip): ?int
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $unpacked = unpack('N', $packed);

        return $unpacked !== false ? $unpacked[1] : null;
    }
}
