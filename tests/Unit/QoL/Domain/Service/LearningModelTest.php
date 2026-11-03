<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\Service;

use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\ValueObject\UtilizationSample;
use PHPUnit\Framework\TestCase;

class LearningModelTest extends TestCase
{
    public function testMinSamplesConstantIs50(): void
    {
        $this->assertSame(50, LearningModel::MIN_SAMPLES);
    }

    public function testPredictReturnsNullBeforeMinSamples(): void
    {
        $model = new LearningModel();

        for ($i = 0; $i < LearningModel::MIN_SAMPLES - 1; $i++) {
            $model->addSample($this->createSample());
        }

        $this->assertFalse($model->isReady());
        $this->assertNull($model->predict(1080, 5_000_000, false));
    }

    public function testSampleCountReflectsAddedSamples(): void
    {
        $model = new LearningModel();

        for ($i = 0; $i < 10; $i++) {
            $model->addSample($this->createSample());
        }

        $this->assertSame(10, $model->sampleCount());
    }

    public function testIsReadyTrueAfterMinSamples(): void
    {
        $model = new LearningModel();

        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $model->addSample($this->createSample());
        }

        $this->assertTrue($model->isReady());
    }

    public function testPredictReturnsValueAfterTraining(): void
    {
        $model = $this->trainModelWithKnownRelationship();

        $predicted = $model->predict(1080, 5_000_000, false);

        $this->assertNotNull($predicted);
        // Known formula: 5 + 20*(1080/2160) + 40*(5M/20M) = 5 + 10 + 10 = 25
        $this->assertEqualsWithDelta(25.0, $predicted, 2.0);
    }

    public function testPredictCorrelatesWithBitrate(): void
    {
        $model = $this->trainModelWithKnownRelationship();

        $costLow = $model->predict(1080, 800_000, false);
        $costHigh = $model->predict(1080, 20_000_000, false);

        $this->assertNotNull($costLow);
        $this->assertNotNull($costHigh);
        $this->assertGreaterThan($costLow, $costHigh, 'Higher bitrate should predict higher CPU cost');
    }

    public function testPredictAccountsForHardwareAcceleration(): void
    {
        $model = $this->trainModelWithKnownRelationship();

        $softwareCost = $model->predict(1080, 5_000_000, false);
        $hardwareCost = $model->predict(1080, 5_000_000, true);

        $this->assertNotNull($softwareCost);
        $this->assertNotNull($hardwareCost);
        $this->assertLessThan($softwareCost, $hardwareCost, 'Hardware acceleration should predict lower CPU cost');
    }

    public function testHardwareSamplesDoNotRaiseTheSoftwarePrediction(): void
    {
        $software = $this->trainModelWithKnownRelationship(hardwareAccelerated: false);
        $isolated = $software->predict(1080, 5_000_000, false);

        $mixed = $this->trainModelWithKnownRelationship(hardwareAccelerated: false);
        // Hardware encodes that cost far more CPU than the software relationship.
        $this->addKnownRelationship($mixed, hardwareAccelerated: true, offset: 60.0);

        $this->assertNotNull($isolated);
        $this->assertEqualsWithDelta($isolated, $mixed->predict(1080, 5_000_000, false), 0.000001);
        $this->assertEqualsWithDelta($isolated + 60.0, $mixed->predict(1080, 5_000_000, true), 0.01);
    }

    public function testEncodeKindIsUntrainedUntilItHasItsOwnMinimumSamples(): void
    {
        $model = $this->trainModelWithKnownRelationship(hardwareAccelerated: false);

        $this->assertNotNull($model->predict(1080, 5_000_000, false));
        $this->assertNull($model->predict(1080, 5_000_000, true));
    }

    public function testTierAverageForAnEncodeKindIgnoresTheOtherKind(): void
    {
        $model = new LearningModel();

        $model->addSample($this->createSample(cpuPercent: 30.0, qualityTier: '1080p'));
        $model->addSample($this->createSample(cpuPercent: 4.0, hardwareAccelerated: true, qualityTier: '1080p'));

        $this->assertEqualsWithDelta(30.0, $model->averageCostForTier('1080p', false), 0.01);
        $this->assertEqualsWithDelta(4.0, $model->averageCostForTier('1080p', true), 0.01);
        $this->assertEqualsWithDelta(17.0, $model->averageCostForTier('1080p'), 0.01);
    }

    public function testAverageCostForTierReturnsAverageForMatchingSamples(): void
    {
        $model = new LearningModel();

        $model->addSample($this->createSample(cpuPercent: 30.0, qualityTier: '1080p'));
        $model->addSample($this->createSample(cpuPercent: 50.0, qualityTier: '1080p'));
        $model->addSample($this->createSample(cpuPercent: 10.0, qualityTier: '720p'));

        $this->assertEqualsWithDelta(40.0, $model->averageCostForTier('1080p'), 0.01);
    }

    public function testAverageCostForTierReturnsNullForNoMatches(): void
    {
        $model = new LearningModel();

        $model->addSample($this->createSample(qualityTier: '1080p'));

        $this->assertNull($model->averageCostForTier('4K'));
    }

    public function testResetClearsSamplesAndCoefficients(): void
    {
        $model = $this->trainModelWithKnownRelationship();

        $this->assertTrue($model->isReady());
        $this->assertNotNull($model->predict(1080, 5_000_000, false));

        $model->reset();

        $this->assertFalse($model->isReady());
        $this->assertSame(0, $model->sampleCount());
        $this->assertNull($model->predict(1080, 5_000_000, false));
    }

    public function testGetStateAndRestoreStateRoundTrip(): void
    {
        $model = $this->trainModelWithKnownRelationship();
        $originalPredict = $model->predict(1080, 5_000_000, false);
        $state = $model->getState();

        $restored = new LearningModel();
        $restored->restoreState($state);

        $this->assertSame($model->sampleCount(), $restored->sampleCount());
        $this->assertTrue($restored->isReady());
        $this->assertEqualsWithDelta($originalPredict, $restored->predict(1080, 5_000_000, false), 0.001);
    }

    /**
     * Train both encode kinds with known linear relationships so predict() outputs are deterministic.
     * Formula: cpu = 5 + 20*(height/2160) + 40*(bitrate/20M), 10 lower for hardware encodes.
     */
    private function trainModelWithKnownRelationship(?bool $hardwareAccelerated = null): LearningModel
    {
        $model = new LearningModel();
        foreach ($hardwareAccelerated === null ? [false, true] : [$hardwareAccelerated] as $kind) {
            $this->addKnownRelationship($model, $kind, $kind ? -10.0 : 0.0);
        }

        return $model;
    }

    private function addKnownRelationship(LearningModel $model, bool $hardwareAccelerated, float $offset): void
    {
        $heights = [360, 480, 720, 1080, 1440, 2160];
        $bitrates = [800_000, 1_400_000, 2_800_000, 5_000_000, 10_000_000, 20_000_000];

        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $height = $heights[$i % 6];
            $bitrate = $bitrates[intdiv($i, 6) % 6];

            $cpu = 5.0
                + 20.0 * ($height / 2160.0)
                + 40.0 * ($bitrate / 20_000_000.0)
                + $offset;

            $model->addSample($this->createSample(
                cpuPercent: $cpu,
                sourceHeight: $height,
                targetBitrate: $bitrate,
                hardwareAccelerated: $hardwareAccelerated,
            ));
        }
    }

    private function createSample(
        float $cpuPercent = 50.0,
        int $sourceHeight = 1080,
        int $targetBitrate = 5_000_000,
        bool $hardwareAccelerated = false,
        string $qualityTier = '1080p',
    ): UtilizationSample {
        return new UtilizationSample(
            cpuPercent: $cpuPercent,
            encodeFps: 30.0,
            sourceHeight: $sourceHeight,
            sourceCodec: 'h264',
            hardwareAccelerated: $hardwareAccelerated,
            targetBitrate: $targetBitrate,
            qualityTier: $qualityTier,
            activeStreams: 1,
        );
    }
}
