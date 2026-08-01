<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class TokenMetadataTest extends TestCase
{
    private Uuid $tokenId;

    protected function setUp(): void
    {
        $this->tokenId = Uuid::v4();
    }

    public function testCreateWithMinimalParams(): void
    {
        $meta = TokenMetadata::create($this->tokenId);

        $this->assertSame($this->tokenId, $meta->getTokenId());
        $this->assertNull($meta->getUserAgent());
        $this->assertNull($meta->getIpAddress());
        $this->assertSame(0, $meta->getIpChangeCount());
        $this->assertEmpty($meta->getIpHistory());
    }

    public function testCreateWithAllParams(): void
    {
        $meta = TokenMetadata::create(
            $this->tokenId,
            userAgent: 'Mozilla/5.0',
            deviceOperatingSystem: 'Windows',
            deviceName: 'Desktop',
            clientFingerprint: str_repeat('a', 64),
            sessionId: 'sess-123',
            ipAddress: '1.2.3.4',
            countryCode: 'US',
            city: 'New York',
        );

        $this->assertSame('Mozilla/5.0', $meta->getUserAgent());
        $this->assertSame('Windows', $meta->getDeviceOperatingSystem());
        $this->assertSame('Desktop', $meta->getDeviceName());
        $this->assertSame(str_repeat('a', 64), $meta->getClientFingerprint());
        $this->assertSame('sess-123', $meta->getSessionId());
        $this->assertSame('1.2.3.4', $meta->getIpAddress());
        $this->assertSame('US', $meta->getCountryCode());
        $this->assertSame('New York', $meta->getCity());
        $this->assertCount(1, $meta->getIpHistory());
    }

    public function testCreateWithIpAddsToHistory(): void
    {
        $meta = TokenMetadata::create($this->tokenId, ipAddress: '1.1.1.1');

        $this->assertCount(1, $meta->getIpHistory());
        $this->assertSame('1.1.1.1', $meta->getIpHistory()[0]['ip']);
    }

    public function testCreateWithoutIpHasEmptyHistory(): void
    {
        $meta = TokenMetadata::create($this->tokenId);

        $this->assertEmpty($meta->getIpHistory());
    }

    public function testRecordIpChange(): void
    {
        $meta = TokenMetadata::create($this->tokenId, ipAddress: '1.1.1.1');

        $meta->recordIpChange('2.2.2.2');

        $this->assertSame('2.2.2.2', $meta->getIpAddress());
        $this->assertSame(1, $meta->getIpChangeCount());
        $this->assertCount(2, $meta->getIpHistory());
        $this->assertSame('2.2.2.2', $meta->getIpHistory()[1]['ip']);
    }

    public function testRecordIpChangeSameIpIsNoOp(): void
    {
        $meta = TokenMetadata::create($this->tokenId, ipAddress: '1.1.1.1');
        $before = $meta->getUpdatedAt();

        $meta->recordIpChange('1.1.1.1');

        $this->assertSame(0, $meta->getIpChangeCount());
        $this->assertCount(1, $meta->getIpHistory());
        $this->assertEquals($before, $meta->getUpdatedAt());
    }

    public function testReconstitute(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable();
        $history = [['ip' => '1.1.1.1', 'seen_at' => $now->format(\DateTimeInterface::ATOM)]];

        $meta = TokenMetadata::reconstitute(
            id: $id,
            tokenId: $this->tokenId,
            userAgent: 'TestAgent',
            deviceOperatingSystem: 'Linux',
            deviceName: null,
            clientFingerprint: null,
            sessionId: null,
            ipAddress: '1.1.1.1',
            ipHistory: $history,
            ipChangeCount: 2,
            countryCode: null,
            city: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->assertSame($id, $meta->getId());
        $this->assertSame('TestAgent', $meta->getUserAgent());
        $this->assertSame('Linux', $meta->getDeviceOperatingSystem());
        $this->assertSame(2, $meta->getIpChangeCount());
    }
}
