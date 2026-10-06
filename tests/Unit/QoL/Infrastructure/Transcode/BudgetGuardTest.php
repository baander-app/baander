<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Transcode;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Transcode\BudgetGuard;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BudgetGuardTest extends TestCase
{
    public function testSampledCpuAboveTheProfileCapRejectsDispatch(): void
    {
        $governor = $this->governor(ready: true);
        $governor->allocateStream(Uuid::generate(), '1080p', 30.0);

        try {
            (new BudgetGuard($governor, $this->sampler(85.0)))->guardDispatch(Uuid::generate());
            self::fail('Expected the budget guard to reject dispatch.');
        } catch (StreamBudgetExhausted $rejection) {
            self::assertSame(1, $rejection->activeStreams);
            self::assertEqualsWithDelta(0.85, $rejection->budgetUsed, 0.000001);
            self::assertSame('mid_stream_guard', $rejection->requestedTier);
        }
    }

    public function testSampledCpuWithinTheProfileCapAllowsDispatch(): void
    {
        $governor = $this->governor(ready: true);
        self::assertSame(GovernorState::Active, $governor->getState());

        (new BudgetGuard($governor, $this->sampler(80.0)))->guardDispatch(Uuid::generate());
    }

    public function testLearningGovernorNeverGatesDispatch(): void
    {
        $governor = $this->governor(ready: false);
        self::assertSame(GovernorState::Learning, $governor->getState());

        (new BudgetGuard($governor, $this->sampler(99.0)))->guardDispatch(Uuid::generate());
    }

    private function governor(bool $ready): StreamGovernor
    {
        $model = new LearningModel();
        for ($i = 0; $ready && $i < LearningModel::MIN_SAMPLES; $i++) {
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
        $governor = new StreamGovernor($model, new QualityLadderPort());
        $governor->activate();

        return $governor;
    }

    private function sampler(float $cpuPercent): CpuGpuSampler
    {
        $sampler = new CpuGpuSampler(new NullLogger());
        $sampler->boot();
        $table = (new \ReflectionProperty($sampler, 'table'))->getValue($sampler);
        self::assertInstanceOf(\Swoole\Table::class, $table);
        $table->set('__latest', ['cpu_percent' => $cpuPercent, 'gpu_percent' => 0.0, 'timestamp' => time()]);

        return $sampler;
    }
}
