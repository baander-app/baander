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
        self::assertSame(['encoder_profile', 'governor'], array_keys($saved));
        self::assertSame(['state', 'model'], array_keys($saved['governor']));
        self::assertSame($saved, $persister->load());
        self::assertSame(['governor_state.json'], array_map('basename', glob($this->directory . '/*') ?: []));
    }

    private function persister(StreamGovernor $governor): LearningDataPersister
    {
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('software');

        return new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());
    }
}
