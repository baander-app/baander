<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\ValueObject;

use App\QoL\Domain\ValueObject\StreamAllocation;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class StreamAllocationTest extends TestCase
{
    public function testConstructorExposesPublicReadonlyProperties(): void
    {
        $jobId = Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');
        $allocation = new StreamAllocation($jobId, '1080p', 42.5);

        $this->assertTrue($allocation->jobId->equals($jobId));
        $this->assertSame('1080p', $allocation->qualityTier);
        $this->assertSame(42.5, $allocation->predictedCost);
        $this->assertInstanceOf(DateTimeImmutable::class, $allocation->allocatedAt);
    }

    public function testJsonSerializeUsesSnakeCaseKeys(): void
    {
        $jobId = Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');
        $allocation = new StreamAllocation($jobId, '4K', 60.0);

        $json = $allocation->jsonSerialize();

        $this->assertSame('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $json['job_id']);
        $this->assertSame('4K', $json['quality_tier']);
        $this->assertSame(60.0, $json['predicted_cost']);
        $this->assertArrayHasKey('allocated_at', $json);
        $this->assertIsString($json['allocated_at']);
    }

    public function testAllocatedAtRoundTripsThroughSerializeAndFromArray(): void
    {
        $allocatedAt = new DateTimeImmutable('2026-06-13T10:30:00+00:00');
        $original = new StreamAllocation(
            jobId: Uuid::v4(),
            qualityTier: '720p',
            predictedCost: 25.0,
            allocatedAt: $allocatedAt,
        );

        $restored = StreamAllocation::fromArray($original->jsonSerialize());

        $this->assertSame(
            $allocatedAt->format(DateTimeImmutable::ATOM),
            $restored->allocatedAt->format(DateTimeImmutable::ATOM),
        );
    }

    public function testFromArrayAppliesDefaultsForMissingOptionalFields(): void
    {
        $restored = StreamAllocation::fromArray([
            'job_id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        ]);

        $this->assertSame('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $restored->jobId->toString());
        $this->assertSame('', $restored->qualityTier);
        $this->assertSame(0.0, $restored->predictedCost);
        $this->assertInstanceOf(DateTimeImmutable::class, $restored->allocatedAt);
    }

    public function testDefaultAllocatedAtIsCurrentTime(): void
    {
        $before = new DateTimeImmutable('-1 second');

        $allocation = new StreamAllocation(Uuid::v4(), '1080p', 10.0);

        $after = new DateTimeImmutable('+1 second');
        $this->assertGreaterThanOrEqual($before, $allocation->allocatedAt);
        $this->assertLessThanOrEqual($after, $allocation->allocatedAt);
    }
}
