<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\ValueObject;

use App\Discovery\Domain\ValueObject\ServerStatus;
use PHPUnit\Framework\TestCase;

final class ServerStatusTest extends TestCase
{
    public function testCasesHaveExpectedValues(): void
    {
        $this->assertSame('online', ServerStatus::Online->value);
        $this->assertSame('offline', ServerStatus::Offline->value);
        $this->assertSame('maintenance', ServerStatus::Maintenance->value);
    }

    public function testFromValueReturnsCorrectCase(): void
    {
        $this->assertSame(ServerStatus::Online, ServerStatus::from('online'));
        $this->assertSame(ServerStatus::Offline, ServerStatus::from('offline'));
        $this->assertSame(ServerStatus::Maintenance, ServerStatus::from('maintenance'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(\ValueError::class);

        ServerStatus::from('degraded');
    }

    public function testLabelsAreHumanReadable(): void
    {
        $this->assertSame('Online', ServerStatus::Online->label());
        $this->assertSame('Offline', ServerStatus::Offline->label());
        $this->assertSame('Maintenance', ServerStatus::Maintenance->label());
    }
}
