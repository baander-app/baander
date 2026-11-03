<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\Swoole\AlgorithmProfileTable;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;
use App\QoL\Infrastructure\Worker\WorkerQoLLifecycle;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Timer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WorkerQoLLifecycleTest extends TestCase
{
    public function testWorkerLoadsProfileMonitorsAndFlushesBeforeStoppingItsTimer(): void
    {
        $directory = sys_get_temp_dir() . '/baander-qol-lifecycle-' . bin2hex(random_bytes(8));
        $logger = new NullLogger();
        new AlgorithmProfileTable($directory, $logger)->set(AlgorithmProfile::Aggressive);
        $profiles = new AlgorithmProfileTable($directory, $logger);
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), $profiles);
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('none/libx265');
        $persister = new LearningDataPersister($governor, $fingerprint, $logger, $directory, new JsonEncoder());
        $lifecycle = new WorkerQoLLifecycle($profiles, $persister, new MidStreamMonitor($governor, $logger));
        try {
            \Swoole\Coroutine\run(function () use ($lifecycle, $profiles, $governor, $persister): void {
                $before = Timer::stats()['num'];
                $lifecycle->start();
                try {
                    self::assertSame(AlgorithmProfile::Aggressive, $profiles->get());
                    self::assertSame($before + 1, Timer::stats()['num']);
                    $governor->recordSample(new UtilizationSample(25.0, 30.0, 1080, 'h264', false, 5000000, '1080p', 1));
                } finally {
                    $lifecycle->stop();
                }
                self::assertSame($before, Timer::stats()['num']);
                $saved = $persister->load();
                self::assertNotNull($saved);
                self::assertSame(LearningDataPersister::FORMAT_VERSION, $saved['version']);
                self::assertCount(1, $saved['governor']['model']['samples']);
            });
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
