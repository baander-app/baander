<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\ValueObject;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\StreamAllocation;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

class QoLValueObjectsTest extends TestCase
{
    public function testGovernorStateHasExactlyTwoCases(): void
    {
        $this->assertSame('learning', GovernorState::Learning->value);
        $this->assertSame('active', GovernorState::Active->value);
        $this->assertCount(2, GovernorState::cases());
    }

    public function testAlgorithmProfileBudgetCaps(): void
    {
        $this->assertSame(0.70, AlgorithmProfile::Conservative->budgetCap());
        $this->assertSame(0.80, AlgorithmProfile::Balanced->budgetCap());
        $this->assertSame(0.90, AlgorithmProfile::Aggressive->budgetCap());
    }

    public function testAlgorithmProfileLabelsContainPercentage(): void
    {
        $this->assertStringContainsString('70%', AlgorithmProfile::Conservative->label());
        $this->assertStringContainsString('80%', AlgorithmProfile::Balanced->label());
        $this->assertStringContainsString('90%', AlgorithmProfile::Aggressive->label());
    }

    public function testUtilizationSampleJsonSerializeAndFromArrayRoundTrip(): void
    {
        $original = new UtilizationSample(
            cpuPercent: 45.5,
            gpuPercent: 30.2,
            encodeFps: 60.0,
            sourceHeight: 1080,
            sourceCodec: 'h264',
            hardwareAccelerated: true,
            targetBitrate: 5_000_000,
            qualityTier: '1080p',
            activeStreams: 3,
        );

        $json = $original->jsonSerialize();
        $restored = UtilizationSample::fromArray($json);

        $this->assertSame($original->cpuPercent, $restored->cpuPercent);
        $this->assertSame($original->gpuPercent, $restored->gpuPercent);
        $this->assertSame($original->encodeFps, $restored->encodeFps);
        $this->assertSame($original->sourceHeight, $restored->sourceHeight);
        $this->assertSame($original->sourceCodec, $restored->sourceCodec);
        $this->assertSame($original->hardwareAccelerated, $restored->hardwareAccelerated);
        $this->assertSame($original->targetBitrate, $restored->targetBitrate);
        $this->assertSame($original->qualityTier, $restored->qualityTier);
        $this->assertSame($original->activeStreams, $restored->activeStreams);
    }

    public function testUtilizationSampleFromArrayHandlesDefaults(): void
    {
        $sample = UtilizationSample::fromArray([]);

        $this->assertSame(0.0, $sample->cpuPercent);
        $this->assertSame(0, $sample->sourceHeight);
        $this->assertFalse($sample->hardwareAccelerated);
    }

    public function testStreamAllocationJsonSerializeAndFromArrayRoundTrip(): void
    {
        $jobId = Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');

        $original = new StreamAllocation(
            jobId: $jobId,
            qualityTier: '4K',
            predictedCost: 55.5,
        );

        $json = $original->jsonSerialize();
        $restored = StreamAllocation::fromArray($json);

        $this->assertSame($original->jobId->toString(), $restored->jobId->toString());
        $this->assertSame($original->qualityTier, $restored->qualityTier);
        $this->assertSame($original->predictedCost, $restored->predictedCost);
    }

    public function testStreamBudgetExhaustedToResponseDataShape(): void
    {
        $exception = new StreamBudgetExhausted(
            activeStreams: 3,
            budgetUsed: 0.92,
            requestedTier: '4K',
        );

        $data = $exception->toResponseData();

        $this->assertSame('stream_budget_exhausted', $data['error']);
        $this->assertSame(3, $data['active_streams']);
        $this->assertSame(0.92, $data['budget_used']);
        $this->assertSame('4K', $data['requested_tier']);
        $this->assertStringContainsString('3', $data['message']);
        $this->assertStringContainsString('92', $data['message']);
    }

    public function testStreamBudgetExhaustedUsesDefaultMessageWhenNoneProvided(): void
    {
        $exception = new StreamBudgetExhausted(
            activeStreams: 2,
            budgetUsed: 0.85,
            requestedTier: '1440p',
        );

        $this->assertStringContainsString('1440p', $exception->getMessage());
        $this->assertStringContainsString('85', $exception->getMessage());
    }
}
