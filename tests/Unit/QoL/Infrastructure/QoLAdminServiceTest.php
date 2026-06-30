<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure;

use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\QoLAdminService;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use ValueError;

final class QoLAdminServiceTest extends TestCase
{
    public function testGetStatusReportsLearningDefaults(): void
    {
        $service = $this->createService();

        $status = $service->getStatus();

        $this->assertSame('learning', $status['state']);
        $this->assertSame('balanced', $status['profile']);
        $this->assertSame(0, $status['active_streams']);
        $this->assertSame(0, $status['sample_count']);
        $this->assertFalse($status['model_ready']);
        $this->assertSame(0.80, $status['budget_cap']);
    }

    public function testGetStatusReflectsProfileChange(): void
    {
        $governor = new StreamGovernor(new LearningModel());
        $service = new QoLAdminService($governor);
        $governor->setProfile(AlgorithmProfile::Conservative);

        $status = $service->getStatus();

        $this->assertSame('conservative', $status['profile']);
        $this->assertSame(0.70, $status['budget_cap']);
    }

    public function testGetStatusReflectsActiveStreamCount(): void
    {
        $governor = new StreamGovernor(new LearningModel());
        $service = new QoLAdminService($governor);
        $governor->allocateStream(new Uuid(), '1080p', 30.0);
        $governor->allocateStream(new Uuid(), '720p', 20.0);

        $status = $service->getStatus();

        $this->assertSame(2, $status['active_streams']);
    }

    public function testGetStatusReportsReadyModelAfterTraining(): void
    {
        $governor = new StreamGovernor($this->createReadyModel());
        $service = new QoLAdminService($governor);

        $status = $service->getStatus();

        $this->assertTrue($status['model_ready']);
        $this->assertSame(LearningModel::MIN_SAMPLES, $status['sample_count']);
    }

    public function testGetActiveStreamsMapsAllocationsWithRoundedCost(): void
    {
        $governor = new StreamGovernor(new LearningModel());
        $service = new QoLAdminService($governor);
        $jobId = Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');
        $governor->allocateStream($jobId, '4K', 55.567);

        $streams = $service->getActiveStreams();

        $this->assertCount(1, $streams);
        $this->assertSame('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $streams[0]['job_id']);
        $this->assertSame('4K', $streams[0]['quality_tier']);
        $this->assertEqualsWithDelta(55.57, $streams[0]['predicted_cost'], 0.001);
    }

    public function testGetActiveStreamsEmptyWhenNoneAllocated(): void
    {
        $this->assertSame([], $this->createService()->getActiveStreams());
    }

    public function testSetProfileReturnsNormalisedValueAndMutatesGovernor(): void
    {
        $governor = new StreamGovernor(new LearningModel());
        $service = new QoLAdminService($governor);

        $returned = $service->setProfile('aggressive');

        $this->assertSame('aggressive', $returned);
        $this->assertSame(AlgorithmProfile::Aggressive, $governor->getProfile());
    }

    public function testSetProfileThrowsValueErrorForUnknownValue(): void
    {
        $this->expectException(ValueError::class);

        $this->createService()->setProfile('turbo');
    }

    public function testResetLearningReturnsLearningAndClearsStreams(): void
    {
        $governor = new StreamGovernor(new LearningModel());
        $service = new QoLAdminService($governor);
        $governor->allocateStream(new Uuid(), '1080p', 30.0);

        $returned = $service->resetLearning();

        $this->assertSame('learning', $returned);
        $this->assertSame(GovernorState::Learning, $governor->getState());
        $this->assertSame(0, $governor->getActiveStreamCount());
    }

    private function createService(): QoLAdminService
    {
        return new QoLAdminService(new StreamGovernor(new LearningModel()));
    }

    private function createReadyModel(): LearningModel
    {
        $model = new LearningModel();

        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $model->addSample($this->createSample());
        }

        return $model;
    }

    private function createSample(): UtilizationSample
    {
        return new UtilizationSample(
            cpuPercent: 50.0,
            gpuPercent: 0.0,
            encodeFps: 30.0,
            sourceHeight: 1080,
            sourceCodec: 'h264',
            hardwareAccelerated: false,
            targetBitrate: 5_000_000,
            qualityTier: '1080p',
            activeStreams: 1,
        );
    }
}
