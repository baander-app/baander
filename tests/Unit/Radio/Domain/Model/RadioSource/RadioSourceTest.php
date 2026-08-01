<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Domain\Model\RadioSource;

use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\Model\RadioSource\RadioSourceState;
use App\Radio\Domain\ValueObject\SyncConfig;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RadioSourceTest extends TestCase
{
    public function testCreateWithConfig(): void
    {
        $syncConfig = new SyncConfig(
            syncUrl: 'https://example.com/api',
            schedule: '0 */6 * * *',
            config: ['timeout' => 30],
        );

        $source = RadioSource::create(
            name: 'IPRD',
            type: 'iprd',
            syncConfig: $syncConfig,
        );

        $this->assertInstanceOf(Uuid::class, $source->getId());
        $this->assertSame('IPRD', $source->getName());
        $this->assertSame('iprd', $source->getType());
        $this->assertSame('https://example.com/api', $source->getSyncConfig()->syncUrl);
        $this->assertSame('0 */6 * * *', $source->getSyncConfig()->schedule);
        $this->assertSame(['timeout' => 30], $source->getSyncConfig()->config);
        $this->assertTrue($source->isActive());
        $this->assertInstanceOf(DateTimeImmutable::class, $source->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $source->getUpdatedAt());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Radio source name cannot be empty.');

        RadioSource::create(
            name: '',
            type: 'iprd',
            syncConfig: new SyncConfig('https://example.com', null, []),
        );
    }

    public function testCreateThrowsOnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Radio source type cannot be empty.');

        RadioSource::create(
            name: 'IPRD',
            type: '',
            syncConfig: new SyncConfig('https://example.com', null, []),
        );
    }

    public function testDeactivate(): void
    {
        $source = RadioSource::create(
            name: 'IPRD',
            type: 'iprd',
            syncConfig: new SyncConfig('https://example.com', null, []),
        );

        $this->assertTrue($source->isActive());
        $source->deactivate();
        $this->assertFalse($source->isActive());
    }

    public function testReconstituteRoundtrip(): void
    {
        $id = Uuid::v7();
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-01 12:00:00');
        $syncConfig = new SyncConfig('https://example.com/api', '0 */6 * * *', ['key' => 'val']);

        $state = new RadioSourceState(
            id: $id,
            name: 'IPRD',
            type: 'iprd',
            syncConfig: $syncConfig,
            isActive: false,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $source = RadioSource::reconstitute($state);

        $this->assertTrue($source->getId()->equals($id));
        $this->assertSame('IPRD', $source->getName());
        $this->assertSame('iprd', $source->getType());
        $this->assertFalse($source->isActive());
        $this->assertEquals($createdAt, $source->getCreatedAt());
        $this->assertEquals($updatedAt, $source->getUpdatedAt());
        $this->assertSame('https://example.com/api', $source->getSyncConfig()->syncUrl);
    }
}
