<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MessengerWorkerHealthTest extends TestCase
{
    private string $path;
    private MockClock $clock;
    private MessengerWorkerHealth $health;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/baander-worker-test-' . bin2hex(random_bytes(12));
        $this->clock = new MockClock('2026-10-02T10:00:00Z');
        $this->health = new MessengerWorkerHealth($this->clock, $this->path);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testMissingStartingAndStoppedWorkersAreNotReady(): void
    {
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
        $this->health->record('starting');
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
        $this->health->record('idle');
        self::assertSame(HealthStatus::Healthy, $this->health->check()->status);
        $this->health->record('stopped');
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
    }

    public function testIdleHeartbeatExpiresButAnActiveJobHasTheTransportLeaseWindow(): void
    {
        $this->health->record('idle');
        $this->clock->sleep(46);
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
        $this->health->record('busy', 12.5, $this->clock->now()->getTimestamp());
        $this->clock->sleep(120);
        $result = $this->health->check();
        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame(12.5, $result->details['lastDeliveryQueueAgeSeconds']);
        $this->clock->sleep(3600);
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
    }

    public function testPidReuseDoesNotMakeAnOldHeartbeatHealthy(): void
    {
        $this->health->record('idle');
        $data = json_decode(file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $data['processStart'] = 'not-this-process';
        file_put_contents($this->path, json_encode($data, JSON_THROW_ON_ERROR));
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
    }

    public function testCorruptHeartbeatFailsClosed(): void
    {
        file_put_contents($this->path, '{broken');
        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
    }
}
