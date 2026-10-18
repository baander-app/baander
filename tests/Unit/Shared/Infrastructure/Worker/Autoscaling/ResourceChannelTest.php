<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Infrastructure\Worker\Autoscaling\ResourceChannel;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceObservation;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use PHPUnit\Framework\TestCase;

final class ResourceChannelTest extends TestCase
{
    private string $directory;
    private string $boot;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-resource-' . bin2hex(random_bytes(8));
        $this->boot = str_repeat('a', 32);
        mkdir($this->directory . '/' . $this->boot, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/' . $this->boot . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/' . $this->boot);
        rmdir($this->directory);
    }

    public function testPrivateBoundedPublicationPreservesSampleAge(): void
    {
        $channel = $this->channel();
        self::assertSame('unavailable', $channel->read(10)->state);
        $channel->publish(new ResourceObservation('known', 10, 0.2, 0.01, 0.3, 0.4));
        self::assertSame(0.3, $channel->read(20)->memoryRatio);
        self::assertSame(10.0, $channel->read(20)->sampledAt);
        $path = $this->directory . '/' . $this->boot . '/resource.json';
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertLessThanOrEqual(4096, filesize($path));
    }

    public function testForeignOversizedMalformedAndFutureSamplesStayUnavailable(): void
    {
        $channel = $this->channel();
        $channel->publish(new ResourceObservation('known', 10, 0.2, 0.01, 0.3, 0.4));
        self::assertSame('unavailable', $this->channel(2)->read(10)->state);
        self::assertSame('unavailable', $channel->read(9)->state);
        $path = $this->directory . '/' . $this->boot . '/resource.json';
        file_put_contents($path, str_repeat('x', 4097));
        self::assertSame('unavailable', $channel->read(10)->state);
        file_put_contents($path, '{}');
        self::assertSame('unavailable', $channel->read(10)->state);
    }

    public function testConcurrentPublicationAllowsOnlyBoundedClockSkew(): void
    {
        $channel = $this->channel();
        foreach ([10.001, 10.25] as $sampledAt) {
            $channel->publish(new ResourceObservation('known', $sampledAt, 0.2, 0.01, 0.3, 0.4));
            self::assertSame('known', $channel->read(10)->state);
            self::assertSame($sampledAt, $channel->read(10)->sampledAt);
        }
        $channel->publish(new ResourceObservation('known', 10.251, 0.2, 0.01, 0.3, 0.4));
        self::assertSame('unavailable', $channel->read(10)->state);
    }

    public function testPublicFileIsNeverAccepted(): void
    {
        $channel = $this->channel();
        $channel->publish(new ResourceObservation('unknown', 10));
        chmod($this->directory . '/' . $this->boot . '/resource.json', 0644);
        self::assertSame('unavailable', $channel->read(10)->state);
    }

    private function channel(int $epoch = 1): ResourceChannel
    {
        return new ResourceChannel($this->directory, new DeploymentLease('worker.baander.app', $this->boot, $epoch));
    }
}
