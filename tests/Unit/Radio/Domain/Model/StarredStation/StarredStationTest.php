<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Domain\Model\StarredStation;

use App\Radio\Domain\Model\StarredStation\StarredStation;
use App\Radio\Domain\Model\StarredStation\StarredStationState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class StarredStationTest extends TestCase
{
    private Uuid $userId;
    private Uuid $stationId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
        $this->stationId = Uuid::v7();
    }

    public function testCreate(): void
    {
        $starred = StarredStation::create(
            userId: $this->userId,
            stationId: $this->stationId,
        );

        $this->assertInstanceOf(Uuid::class, $starred->getId());
        $this->assertTrue($starred->getUserId()->equals($this->userId));
        $this->assertTrue($starred->getStationId()->equals($this->stationId));
        $this->assertInstanceOf(DateTimeImmutable::class, $starred->getStarredAt());
    }

    public function testReconstituteRoundtrip(): void
    {
        $id = Uuid::v7();
        $starredAt = new DateTimeImmutable('2025-06-01 12:00:00');

        $state = new StarredStationState(
            id: $id,
            userId: $this->userId,
            stationId: $this->stationId,
            starredAt: $starredAt,
        );

        $starred = StarredStation::reconstitute($state);

        $this->assertTrue($starred->getId()->equals($id));
        $this->assertTrue($starred->getUserId()->equals($this->userId));
        $this->assertTrue($starred->getStationId()->equals($this->stationId));
        $this->assertEquals($starredAt, $starred->getStarredAt());
    }
}
