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
        // Known formula: 5 + 20*(1080/2160) + 40*(5M/20M) - 10*0 = 5 + 10 + 10 = 25
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
     * Train a model with a known linear relationship so predict() outputs are deterministic.
     * Formula: cpu = 5 + 20*(height/2160) + 40*(bitrate/20M) - 10*hwaccel
     */
    private function trainModelWithKnownRelationship(): LearningModel
    {
        $model = new LearningModel();

        $heights = [360, 480, 720, 1080, 1440, 2160];
        $bitrates = [800_000, 1_400_000, 2_800_000, 5_000_000, 10_000_000, 20_000_000];

        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $height = $heights[$i % 6];
            $bitrate = $bitrates[($i + 1) % 6];
            $hwaccel = $i % 2 === 0;

            $cpu = 5.0
                + 20.0 * ($height / 2160.0)
                + 40.0 * ($bitrate / 20_000_000.0)
                - ($hwaccel ? 10.0 : 0.0);

            $model->addSample($this->createSample(
                cpuPercent: $cpu,
                sourceHeight: $height,
                targetBitrate: $bitrate,
                hardwareAccelerated: $hwaccel,
            ));
        }

        return $model;
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
            gpuPercent: 0.0,
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
