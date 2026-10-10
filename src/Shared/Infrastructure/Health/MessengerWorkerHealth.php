<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * The durable consumer's heartbeat, kept in Redis so the web container can read it.
 *
 * Redis is the worker's own transport: with Redis down the worker cannot work either,
 * so an unreachable Redis makes this check not available and leaves the alert to the
 * Redis check. The key has no expiry; its age alone tells a live worker from a dead one.
 */
final readonly class MessengerWorkerHealth
{
    public const string COMPONENT = 'messenger';
    public const string KEY = 'baander:messenger:worker_heartbeat';
    private const array PHASES = ['starting', 'idle', 'busy', 'stopped'];
    private const int IDLE_WINDOW_SECONDS = 45;
    /** Three of the consumer's 30-second keepalive intervals, which refresh a busy heartbeat. */
    private const int BUSY_WINDOW_SECONDS = 90;

    public function __construct(
        private RedisClientFactory $redis,
        private ClockInterface $clock,
        private WorkerHeartbeatSightingInterface $sighting,
    ) {
    }

    /** @throws Throwable when Redis does not store the heartbeat */
    public function record(string $phase, ?float $queueAge = null, ?int $lastDeliveryAt = null): void
    {
        if (!in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException('Invalid worker phase.');
        }
        $value = json_encode([
            'phase' => $phase, 'at' => $this->clock->now()->getTimestamp(),
            'lastDeliveryQueueAgeSeconds' => $queueAge, 'lastDeliveryAt' => $lastDeliveryAt,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if ($this->redis->borrow(static fn (\Redis $redis): mixed => $redis->set(self::KEY, $value)) !== true) {
            throw new \RuntimeException('Redis did not store the worker heartbeat.');
        }
    }

    public function check(): HealthCheckResult
    {
        $started = microtime(true);
        try {
            $value = $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->get(self::KEY));
        } catch (Throwable $e) {
            return $this->result(HealthStatus::NotAvailable, $started, ['reason' => 'Redis unreachable', 'error' => $e->getMessage()]);
        }
        if (!is_string($value)) {
            return $this->sighting->wasSeen()
                ? $this->result(HealthStatus::Unhealthy, $started, ['reason' => 'Worker heartbeat disappeared'])
                : $this->result(HealthStatus::NotAvailable, $started, ['reason' => 'No worker has reported a heartbeat']);
        }
        $this->sighting->markSeen();
        try {
            $data = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data) || !is_int($data['at'] ?? null) || !in_array($data['phase'] ?? null, self::PHASES, true)) {
            return $this->result(HealthStatus::Unhealthy, $started, ['reason' => 'Worker heartbeat invalid']);
        }
        $age = $this->clock->now()->getTimestamp() - $data['at'];
        // A heartbeat from the future (clock skew between containers) counts as fresh.
        $fresh = $age <= ($data['phase'] === 'busy' ? self::BUSY_WINDOW_SECONDS : self::IDLE_WINDOW_SECONDS);
        // The consumer writes 'stopped' on every recycle at its memory limit. A fresh
        // one may be followed by its replacement's 'starting', so it neither alerts nor
        // ends an alert; a stale one means no replacement came.
        $status = match (true) {
            !$fresh => HealthStatus::Unhealthy,
            $data['phase'] === 'stopped' => HealthStatus::NotAvailable,
            default => HealthStatus::Healthy,
        };

        return $this->result($status, $started, [
            'phase' => $data['phase'], 'heartbeatAgeSeconds' => $age,
            'lastDeliveryQueueAgeSeconds' => $data['lastDeliveryQueueAgeSeconds'] ?? null,
            'lastDeliveryAt' => $data['lastDeliveryAt'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $details */
    private function result(HealthStatus $status, float $started, array $details): HealthCheckResult
    {
        return new HealthCheckResult(self::COMPONENT, $status, (microtime(true) - $started) * 1000, $details);
    }
}
