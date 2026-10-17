<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class StreamAdmissionServiceTest extends TestCase
{
    public function testCompletionReleasesAllocationWithoutLearningFromUnavailableUtilization(): void
    {
        $directory = sys_get_temp_dir() . '/baander-admission-' . bin2hex(random_bytes(8));
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), new InMemoryAlgorithmProfileStore());
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $persister = new LearningDataPersister($governor, $fingerprint, new NullLogger(), $directory, new JsonEncoder());
        $service = new StreamAdmissionService($governor, new CpuGpuSampler(new NullLogger()), $persister, new NullLogger());
        $jobId = new Uuid();
        $governor->allocateStream($jobId, '1080p', 25.0);
        self::assertSame(1, $governor->getActiveStreamCount());
        try {
            $service->complete($jobId, '1080p', 5000000, 1080, 'h264', false);
            self::assertSame(0, $governor->getActiveStreamCount());
            self::assertSame(0, $governor->getModel()->sampleCount());
            self::assertFileDoesNotExist($directory . '/governor_state.json');
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
