<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\HostCpus;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MidStreamMonitorTest extends TestCase
{
    private string $directory;
    private StreamGovernor $governor;
    private StreamAdmissionService $admission;
    private MidStreamMonitor $monitor;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-monitor-' . bin2hex(random_bytes(8));
        $model = new LearningModel();
        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $model->addSample(new UtilizationSample(10.0, 30.0, 1080, 'h264', false, 5_000_000, '1080p', 1));
        }
        $this->governor = new StreamGovernor($model, new QualityLadderPort(), new InMemoryAlgorithmProfileStore());
        $this->governor->activate();
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('none/libx265');
        $persister = new LearningDataPersister($this->governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());
        $this->admission = new StreamAdmissionService($this->governor, $persister, new NullLogger(), new HostCpus(16));
        $this->monitor = new MidStreamMonitor($this->governor, new NullLogger());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * Seven other encodes saturate the host from their own children; this child's governor
     * sees only its stream, whose encoder spends 1.6 CPUs of 16 per second of output.
     */
    public function testStreamUnderTheCapIsKeptWhileOtherEncodesSaturateTheHost(): void
    {
        self::assertSame(GovernorState::Active, $this->governor->getState());
        $jobId = Uuid::generate();
        $this->admission->admit($jobId, '1080p', 5_000_000, 1080, false);

        for ($check = 1; $check <= 4; $check++) {
            $this->admission->observe($jobId, 9.6 * $check, 6.0 * $check);
            $this->monitor->check();
        }

        self::assertSame(1, $this->governor->getActiveStreamCount());
        self::assertEqualsWithDelta(10.0, $this->governor->observedLoad(), 0.000001);
    }

    public function testStreamWhoseOwnCostStaysOverTheCapIsReleased(): void
    {
        $jobId = Uuid::generate();
        $this->admission->admit($jobId, '1080p', 5_000_000, 1080, false);

        // 14.4 CPUs of 16 per output second exceeds the balanced 80 percent cap.
        $this->admission->observe($jobId, 86.4, 6.0);
        $this->monitor->check();
        self::assertSame(1, $this->governor->getActiveStreamCount());

        $this->admission->observe($jobId, 172.8, 12.0);
        $this->monitor->check();
        self::assertSame(0, $this->governor->getActiveStreamCount());
    }
}
