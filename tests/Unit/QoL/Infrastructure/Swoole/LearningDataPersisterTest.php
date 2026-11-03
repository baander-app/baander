<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class LearningDataPersisterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-qol-persister-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testSavedLearningStateCarriesNeitherTheProfileNorActiveStreams(): void
    {
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), new InMemoryAlgorithmProfileStore());
        $governor->setProfile(AlgorithmProfile::Conservative);
        $governor->allocateStream(new Uuid(), '1080p', 30.0);
        $persister = $this->persister($governor);

        $persister->persist();

        $saved = json_decode((string) file_get_contents($this->directory . '/governor_state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['version', 'encoder_profile', 'revision', 'governor'], array_keys($saved));
        self::assertSame(LearningDataPersister::FORMAT_VERSION, $saved['version']);
        self::assertIsString($saved['revision']);
        self::assertSame(['state', 'model'], array_keys($saved['governor']));
        self::assertSame($saved, $persister->load());
        self::assertSame(['governor_state.json', 'governor_state.lock'], array_map('basename', glob($this->directory . '/*') ?: []));
    }

    public function testWorkerResetsLearningSavedBeforeStreamCostsWereMeasured(): void
    {
        mkdir($this->directory, 0755, true);
        $samples = [];
        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $samples[] = ['cpu_percent' => 97.0, 'gpu_percent' => 12.0, 'encode_fps' => 0.0, 'source_height' => 1080,
                'source_codec' => 'h264', 'hardware_accelerated' => false, 'target_bitrate' => 5_000_000,
                'quality_tier' => '1080p', 'active_streams' => 8];
        }
        // Host-wide utilization samples as they were saved before the format carried a version.
        file_put_contents($this->directory . '/governor_state.json', json_encode([
            'encoder_profile' => 'software',
            'revision' => str_repeat('a', 32),
            'governor' => ['state' => 'active', 'model' => ['samples' => $samples, 'coefficients' => null]],
        ], JSON_THROW_ON_ERROR));
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), new InMemoryAlgorithmProfileStore());
        $persister = $this->persister($governor);

        self::assertNull($persister->load());
        $persister->restoreForWorker();

        self::assertSame(0, $governor->getModel()->sampleCount());
        self::assertNull($governor->getModel()->averageCostForTier('1080p'));
        $saved = $persister->load();
        self::assertNotNull($saved);
        self::assertSame(LearningDataPersister::FORMAT_VERSION, $saved['version']);
        self::assertNotSame(str_repeat('a', 32), $saved['revision']);
        self::assertSame([], $saved['governor']['model']['samples']);
    }

    private function persister(StreamGovernor $governor): LearningDataPersister
    {
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('software');

        return new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());
    }
}
