<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WorkerLearningPersistenceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-worker-learning-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testConcurrentChildrenMergeSingleCompletionSamplesWithoutDuplicatingTheBaseline(): void
    {
        [$seed, $seedStore] = $this->services();
        $seed->recordSample($this->sample(10.0));
        $seedStore->save();
        [$first, $firstStore] = $this->services();
        [$second, $secondStore] = $this->services();
        $firstStore->restoreForWorker();
        $secondStore->restoreForWorker();
        $first->recordSample($this->sample(20.0));
        $second->recordSample($this->sample(30.0));
        $firstStore->save();
        $secondStore->save();
        $secondStore->save();
        [$restored, $thirdStore] = $this->services();
        $thirdStore->restoreForWorker();
        self::assertSame(3, $restored->getModel()->sampleCount());
        self::assertSame(20.0, $restored->getModel()->averageCostForTier('1080p'));
    }

    public function testAResetCannotBeOverwrittenByAnOlderChildCompletion(): void
    {
        [$oldWorker, $oldStore] = $this->services();
        $oldStore->restoreForWorker();
        $oldWorker->recordSample($this->sample(20.0));
        [, $resetStore] = $this->services();
        $resetStore->save();
        $oldStore->save();
        [$restored, $store] = $this->services();
        $store->restoreForWorker();
        self::assertSame(0, $restored->getModel()->sampleCount());
    }

    public function testAChangedEncoderCannotRestoreOrResurrectPriorLearning(): void
    {
        [$old, $oldStore] = $this->services('none/libx264');
        $oldStore->restoreForWorker();
        $old->recordSample($this->sample(20.0));
        $oldStore->save();
        [$current, $currentStore] = $this->services('none/libx265');
        $currentStore->restoreForWorker();
        self::assertSame(0, $current->getModel()->sampleCount());
        $old->recordSample($this->sample(30.0));
        $oldStore->save();
        self::assertSame('none/libx265', $currentStore->load()['encoder_profile']);
    }

    public function testReadyPersistedLearningActivatesTheNextFreshWorker(): void
    {
        [$first, $store] = $this->services();
        $store->restoreForWorker();
        for ($index = 0; $index < LearningModel::MIN_SAMPLES; ++$index) {
            $first->recordSample($this->sample(99.0));
        }
        $store->save();
        [$next, $nextStore] = $this->services();
        $nextStore->restoreForWorker();
        self::assertSame('active', $next->getState()->value);
        self::assertSame(LearningModel::MIN_SAMPLES, $next->getModel()->sampleCount());
        $this->expectException(\App\QoL\Domain\Exception\StreamBudgetExhausted::class);
        $next->evaluateBudget(1080, 5000000, false, '1080p');
    }

    /** @return array{StreamGovernor, LearningDataPersister} */
    private function services(string $profile = 'none/libx265'): array
    {
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), new InMemoryAlgorithmProfileStore());
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn($profile);
        return [$governor, new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder())];
    }

    private function sample(float $cpu): UtilizationSample
    {
        return new UtilizationSample($cpu, 0.0, 30.0, 1080, 'h264', false, 5000000, '1080p', 1);
    }
}
