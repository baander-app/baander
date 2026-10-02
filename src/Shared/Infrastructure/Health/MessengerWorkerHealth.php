<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Psr\Clock\ClockInterface;

/** Local readiness for the single supervised async consumer in each Linux container. */
final readonly class MessengerWorkerHealth
{
    public function __construct(
        private ClockInterface $clock,
        private string $path = '/tmp/baander-messenger-async.json',
    ) {
    }

    public function record(string $phase, ?float $queueAge = null, ?int $lastDeliveryAt = null): void
    {
        if (!in_array($phase, ['starting', 'idle', 'busy', 'stopped'], true)) {
            throw new \InvalidArgumentException('Invalid worker phase.');
        }
        $pid = getmypid();
        $identity = $pid === false ? null : $this->processStart($pid);
        if ($identity === null) {
            throw new \RuntimeException('Cannot identify the Messenger worker process.');
        }
        $data = json_encode([
            'version' => 1, 'pid' => $pid, 'processStart' => $identity,
            'phase' => $phase, 'updatedAt' => $this->clock->now()->getTimestamp(),
            'lastDeliveryQueueAgeSeconds' => $queueAge, 'lastDeliveryAt' => $lastDeliveryAt,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $temporary = tempnam(dirname($this->path), '.baander-worker-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create worker heartbeat.');
        }
        try {
            if (file_put_contents($temporary, $data) !== strlen($data) || !rename($temporary, $this->path)) {
                throw new \RuntimeException('Cannot publish worker heartbeat.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function check(): HealthCheckResult
    {
        $started = microtime(true);
        $details = ['reason' => 'Worker heartbeat missing or invalid'];
        $healthy = false;
        try {
            $json = @file_get_contents($this->path, length: 4097);
            if ($json !== false && strlen($json) <= 4096) {
                $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
                if (is_array($data) && ($data['version'] ?? null) === 1 && is_int($data['pid'] ?? null)
                    && is_int($data['updatedAt'] ?? null) && is_string($data['processStart'] ?? null)
                    && in_array($data['phase'] ?? null, ['starting', 'idle', 'busy', 'stopped'], true)) {
                    $age = $this->clock->now()->getTimestamp() - $data['updatedAt'];
                    $alive = $data['processStart'] === $this->processStart($data['pid']);
                    // Busy work has the same upper bound as Messenger's redeliver_timeout.
                    $fresh = $age >= 0 && $age <= ($data['phase'] === 'busy' ? 3600 : 45);
                    $healthy = $alive && $fresh && in_array($data['phase'], ['idle', 'busy'], true);
                    $details = [
                        'phase' => $data['phase'], 'processAlive' => $alive, 'heartbeatAgeSeconds' => $age,
                        'lastDeliveryQueueAgeSeconds' => $data['lastDeliveryQueueAgeSeconds'] ?? null,
                        'lastDeliveryAt' => $data['lastDeliveryAt'] ?? null,
                    ];
                }
            }
        } catch (\JsonException) {
            // A truncated or incompatible heartbeat must never report readiness.
        }
        return new HealthCheckResult('messenger', $healthy ? HealthStatus::Healthy : HealthStatus::Unhealthy, (microtime(true) - $started) * 1000, $details);
    }

    private function processStart(int $pid): ?string
    {
        if ($pid < 1) {
            return null;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false || ($end = strrpos($stat, ')')) === false) {
            return null;
        }
        // Fields after the parenthesized comm start at field 3; starttime is field 22.
        $fields = explode(' ', substr($stat, $end + 2));
        return isset($fields[19]) && !in_array($fields[0], ['Z', 'X'], true) ? $fields[19] : null;
    }
}
