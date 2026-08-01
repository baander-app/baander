<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Domain\Model\CountrySubscription;

use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Radio\Domain\Model\CountrySubscription\CountrySubscriptionState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CountrySubscriptionTest extends TestCase
{
    private Uuid $userId;
    private Uuid $sourceId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
        $this->sourceId = Uuid::v7();
    }

    public function testCreateWithUserSourceCountry(): void
    {
        $sub = CountrySubscription::create(
            userId: $this->userId,
            sourceId: $this->sourceId,
            countryCode: 'DE',
        );

        $this->assertInstanceOf(Uuid::class, $sub->getId());
        $this->assertTrue($sub->getUserId()->equals($this->userId));
        $this->assertTrue($sub->getSourceId()->equals($this->sourceId));
        $this->assertSame('DE', $sub->getCountryCode());
        $this->assertNull($sub->getLastSyncedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $sub->getCreatedAt());
    }

    public function testCreateThrowsOnEmptyCountryCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Country code cannot be empty.');

        CountrySubscription::create(
            userId: $this->userId,
            sourceId: $this->sourceId,
            countryCode: '',
        );
    }

    public function testMarkSynced(): void
    {
        $sub = CountrySubscription::create(
            userId: $this->userId,
            sourceId: $this->sourceId,
            countryCode: 'DE',
        );

        $this->assertNull($sub->getLastSyncedAt());

        $now = new DateTimeImmutable();
        $sub->markSynced($now);

        $this->assertEquals($now, $sub->getLastSyncedAt());
    }

    public function testReconstituteRoundtrip(): void
    {
        $id = Uuid::v7();
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $lastSyncedAt = new DateTimeImmutable('2025-06-01 12:00:00');

        $state = new CountrySubscriptionState(
            id: $id,
            userId: $this->userId,
            sourceId: $this->sourceId,
            countryCode: 'US',
            lastSyncedAt: $lastSyncedAt,
            createdAt: $createdAt,
        );

        $sub = CountrySubscription::reconstitute($state);

        $this->assertTrue($sub->getId()->equals($id));
        $this->assertTrue($sub->getUserId()->equals($this->userId));
        $this->assertTrue($sub->getSourceId()->equals($this->sourceId));
        $this->assertSame('US', $sub->getCountryCode());
        $this->assertEquals($lastSyncedAt, $sub->getLastSyncedAt());
        $this->assertEquals($createdAt, $sub->getCreatedAt());
    }
}
