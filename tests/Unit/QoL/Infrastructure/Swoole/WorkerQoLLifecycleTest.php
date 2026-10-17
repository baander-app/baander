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
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;
use App\QoL\Infrastructure\Worker\WorkerQoLLifecycle;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Timer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WorkerQoLLifecycleTest extends TestCase
{
    public function testWorkerLoadsProfileSamplesAndFlushesBeforeStoppingItsTimers(): void
    {
        $directory = sys_get_temp_dir() . '/baander-qol-lifecycle-' . bin2hex(random_bytes(8));
        $logger = new NullLogger();
        new AlgorithmProfileTable($directory, $logger)->set(AlgorithmProfile::Aggressive);
        $originalPath = getenv('PATH');
        file_put_contents($directory . '/nvidia-smi', '#!' . PHP_BINARY . "\n<?php\nfile_put_contents(__DIR__ . '/gpu-probe-started', 'yes');\n");
        chmod($directory . '/nvidia-smi', 0700);
        putenv('PATH=' . $directory . ':' . ($originalPath === false ? '' : $originalPath));
        $profiles = new AlgorithmProfileTable($directory, $logger);
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), $profiles);
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('none/libx265');
        $persister = new LearningDataPersister($governor, $fingerprint, $logger, $directory, new JsonEncoder());
        $sampler = new CpuGpuSampler($logger);
        $lifecycle = new WorkerQoLLifecycle($profiles, $persister, $sampler, new MidStreamMonitor($governor, $sampler, $logger));
        try {
            \Swoole\Coroutine\run(function () use ($lifecycle, $profiles, $sampler, $governor, $persister): void {
                $before = Timer::stats()['num'];
                $lifecycle->start();
                try {
                    self::assertSame(AlgorithmProfile::Aggressive, $profiles->get());
                    Coroutine::sleep(1.1);
                    self::assertNotNull($sampler->getLatest());
                    $governor->recordSample(new UtilizationSample(25.0, 0.0, 30.0, 1080, 'h264', false, 5000000, '1080p', 1));
                } finally {
                    $lifecycle->stop();
                }
                self::assertSame($before, Timer::stats()['num']);
                self::assertCount(1, $persister->load()['governor']['model']['samples']);
            });
            self::assertFileDoesNotExist($directory . '/gpu-probe-started');
        } finally {
            putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
