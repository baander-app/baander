<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Port\AsyncTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Application\Port\TransportStatus;
use App\Shared\Application\Port\TransportStatusInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Redis;
use Throwable;

/**
 * Probes the async transport's Redis stream and counts the failure transport.
 * The stream and group names match the `async` transport in config/packages/messenger.yaml.
 */
final readonly class RedisTransportStatus implements TransportStatusInterface
{
    private const string STREAM = 'messages';
    private const string GROUP = 'baander';

    public function __construct(
        private RedisClientFactory $redisClientFactory,
        private string $consumerName,
        private FailedMessageAdministrationInterface $failedMessages,
    ) {
    }

    public function status(): TransportStatus
    {
        try {
            /** @var array{int, bool} $probe */
            $probe = $this->redisClientFactory->borrow(
                fn (Redis $redis): array => [(int) $redis->xlen(self::STREAM), $this->consumerListed($redis)],
            );
        } catch (Throwable $e) {
            throw new AsyncTransportUnavailableException(sprintf('Redis unavailable: %s', $e->getMessage()), 0, $e);
        }

        return new TransportStatus(
            asyncQueueDepth: $probe[0],
            failedQueueDepth: $this->failedMessages->count(),
            consumerName: $this->consumerName,
            consumerRunning: $probe[1],
        );
    }

    private function consumerListed(Redis $redis): bool
    {
        try {
            // phpredis returns false when the stream or group does not exist.
            $consumers = $redis->xinfo('CONSUMERS', self::STREAM, self::GROUP);
        } catch (Throwable) {
            // XINFO fails when the stream or group does not exist yet.
            return false;
        }

        foreach (is_array($consumers) ? $consumers : [] as $consumer) {
            if (is_array($consumer) && ($consumer['name'] ?? null) === $this->consumerName) {
                return true;
            }
        }

        return false;
    }
}
