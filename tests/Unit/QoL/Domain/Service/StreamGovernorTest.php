<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\Service;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Service\QualityLadder;
use PHPUnit\Framework\TestCase;

class StreamGovernorTest extends TestCase
{
    public function testInitialStateIsLearning(): void
    {
        $governor = $this->createGovernor();

        $this->assertSame(GovernorState::Learning, $governor->getState());
    }

    public function testDefaultProfileIsBalanced(): void
    {
        $governor = $this->createGovernor();

        $this->assertSame(AlgorithmProfile::Balanced, $governor->getProfile());
    }

    public function testLearningStateAllowsAllTiers(): void
    {
        $governor = $this->createGovernor();

        // Never throws, always returns requested tier during Learning
        $this->assertSame('4K', $governor->evaluateBudget(2160, 20_000_000, false, '4K'));
        $this->assertSame('1080p', $governor->evaluateBudget(1080, 5_000_000, false, '1080p'));
    }

    public function testLearningStateGetAllowedTiersReturnsAll(): void
    {
        $governor = $this->createGovernor();

        $allowed = $governor->getAllowedTiers();
        $allTiers = array_map(static fn($t) => $t->name, QualityLadder::defaultTiers());

        $this->assertSame($allTiers, $allowed);
    }

    public function testAllocateStreamTracksActiveStreams(): void
    {
        $governor = $this->createGovernor();
        $jobId = new Uuid();

        $governor->allocateStream($jobId, '1080p', 30.0);

        $this->assertSame(1, $governor->getActiveStreamCount());
        $streams = $governor->getActiveStreams();
        $this->assertSame('1080p', $streams[0]->qualityTier);
    }

    public function testReleaseStreamRemovesFromActiveSet(): void
    {
        $governor = $this->createGovernor();
        $jobId = new Uuid();

        $governor->allocateStream($jobId, '1080p', 30.0);
        $this->assertSame(1, $governor->getActiveStreamCount());

        $governor->releaseStream($jobId);
        $this->assertSame(0, $governor->getActiveStreamCount());
    }

    public function testActivateTransitionsToActiveWhenModelReady(): void
    {
        $model = $this->createReadyModel();
        $governor = new StreamGovernor($model);

        $this->assertSame(GovernorState::Learning, $governor->getState());

        $governor->activate();

        $this->assertSame(GovernorState::Active, $governor->getState());
    }

    public function testActivateDoesNotTransitionWhenModelNotReady(): void
    {
        $governor = $this->createGovernor();

        $governor->activate();

        $this->assertSame(GovernorState::Learning, $governor->getState());
    }

    public function testActiveStateAllowsRequestedTierWhenBudgetAvailable(): void
    {
        // Coefficients: predict ≈ 5 + 40*(bitrate/20M), low cost everywhere
        $governor = $this->createActiveGovernor(coefficients: [5.0, 0.0, 1.0, 0.0]);

        // Balanced cap = 80, no active streams, remaining = 80
        // predict(1080, 5M, false) = 5 + 0 + 1*0.25 + 0 = 5.25 → well within budget
        $this->assertSame('1080p', $governor->evaluateBudget(1080, 5_000_000, false, '1080p'));
    }

    public function testActiveStateWalksDownToCheaperTier(): void
    {
        // Coefficients: predict ≈ 75 + 20*(bitrate/20M)
        // 4K (20M):   75 + 20*1.0  = 95 → rejected (> 80)
        // 1440p (10M): 75 + 20*0.5  = 85 → rejected (> 80)
        // 1080p (5M):  75 + 20*0.25 = 80 → fits (<= 80)
        $governor = $this->createActiveGovernor(coefficients: [75.0, 0.0, 20.0, 0.0]);

        $allowed = $governor->evaluateBudget(1080, 20_000_000, false, '4K');

        $this->assertSame('1080p', $allowed);
    }

    public function testActiveStateThrowsWhenNoTierFits(): void
    {
        // Same coefficients as walk-down test, but Conservative cap = 70
        // Even 1080p (80) > 70 → all tiers rejected
        $governor = $this->createActiveGovernor(
            coefficients: [75.0, 0.0, 20.0, 0.0],
            profile: AlgorithmProfile::Conservative,
        );

        $this->expectException(StreamBudgetExhausted::class);
        $this->expectExceptionMessage('Stream budget exhausted');

        $governor->evaluateBudget(1080, 800_000, false, '360p');
    }

    public function testStreamBudgetExhaustedCarriesContextData(): void
    {
        $governor = $this->createActiveGovernor(
            coefficients: [75.0, 0.0, 20.0, 0.0],
            profile: AlgorithmProfile::Conservative,
        );
        $jobId = new Uuid();
        $governor->allocateStream($jobId, '4K', 60.0);

        try {
            $governor->evaluateBudget(1080, 800_000, false, '360p');
            $this->fail('Expected StreamBudgetExhausted');
        } catch (StreamBudgetExhausted $e) {
            $this->assertSame(1, $e->activeStreams);
            $this->assertGreaterThan(0.0, $e->budgetUsed);
            $this->assertSame('360p', $e->requestedTier);
        }
    }

    public function testSetProfileChangesBudgetCap(): void
    {
        $governor = $this->createGovernor();

        $governor->setProfile(AlgorithmProfile::Aggressive);

        $this->assertSame(AlgorithmProfile::Aggressive, $governor->getProfile());
        $this->assertSame(0.90, $governor->getProfile()->budgetCap());
    }

    public function testResetLearningReturnsToLearningState(): void
    {
        $model = $this->createReadyModel();
        $governor = new StreamGovernor($model);
        $governor->activate();
        $governor->allocateStream(new Uuid(), '1080p', 30.0);

        $this->assertSame(GovernorState::Active, $governor->getState());
        $this->assertSame(1, $governor->getActiveStreamCount());

        $governor->resetLearning();

        $this->assertSame(GovernorState::Learning, $governor->getState());
        $this->assertSame(0, $governor->getActiveStreamCount());
        $this->assertFalse($governor->getModel()->isReady());
    }

    public function testExportStateAndImportStateRoundTrip(): void
    {
        $model = $this->createReadyModel();
        $governor = new StreamGovernor($model);
        $governor->activate();
        $governor->setProfile(AlgorithmProfile::Aggressive);
        $governor->allocateStream(Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'), '4K', 55.0);

        $exported = $governor->exportState();

        $newGovernor = new StreamGovernor(new LearningModel());
        $newGovernor->importState($exported);

        $this->assertSame(GovernorState::Active->value, $exported['state']);
        $this->assertSame('aggressive', $exported['profile']);
        $this->assertSame(GovernorState::Active, $newGovernor->getState());
        $this->assertSame(AlgorithmProfile::Aggressive, $newGovernor->getProfile());
        $this->assertSame(1, $newGovernor->getActiveStreamCount());
    }

    // --- Helpers ---

    private function createGovernor(): StreamGovernor
    {
        return new StreamGovernor(new LearningModel());
    }

    /**
     * Create a LearningModel with MIN_SAMPLES dummy samples so isReady() returns true.
     */
    private function createReadyModel(): LearningModel
    {
        $model = new LearningModel();

        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $model->addSample(new UtilizationSample(
                cpuPercent: 50.0,
                gpuPercent: 0.0,
                encodeFps: 30.0,
                sourceHeight: 1080,
                sourceCodec: 'h264',
                hardwareAccelerated: false,
                targetBitrate: 5_000_000,
                qualityTier: '1080p',
                activeStreams: 1,
            ));
        }

        return $model;
    }

    /**
     * Create an Active governor with deterministic coefficients for predictable predict() output.
     * Coefficients: [intercept, heightCoef, bitrateCoef, hwaccelCoef].
     */
    private function createActiveGovernor(
        array $coefficients,
        AlgorithmProfile $profile = AlgorithmProfile::Balanced,
    ): StreamGovernor {
        $model = $this->createReadyModel();

        // Override coefficients for deterministic predict() behavior while keeping 50 samples
        $model->restoreState([
            'samples' => $model->getState()['samples'],
            'coefficients' => $coefficients,
        ]);

        $governor = new StreamGovernor($model);
        $governor->setProfile($profile);
        $governor->activate();

        return $governor;
    }
}
