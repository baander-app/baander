<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Persists governor learning independently of the algorithm profile and active streams.
 * Fresh media children restore a revision and merge only their new samples under a lock.
 * An explicit save outside that lifecycle replaces the learning revision, fencing old children.
 */
final class LearningDataPersister
{
    private const int PERSIST_INTERVAL_SAMPLES = 10;
    private const float PERSIST_INTERVAL_SECONDS = 5.0;

    private ?string $workerRevision = null;
    private ?string $workerFingerprint = null;
    private int $workerSampleCount = 0;
    private int $sampleCounter = 0;
    private float $lastPersistTime = 0.0;

    public function __construct(
        private readonly StreamGovernor $governor,
        private readonly EncoderProfileFingerprintPortInterface $fingerprint,
        private readonly LoggerInterface $logger,
        private readonly string $stateDir,
        private readonly JsonEncoder $jsonEncoder,
    ) {
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
            throw new RuntimeException('Cannot create the QoL state directory.');
        }
        $this->lastPersistTime = microtime(true);
    }

    public function shouldPersist(): bool
    {
        $this->sampleCounter++;
        if ($this->sampleCounter >= self::PERSIST_INTERVAL_SAMPLES) {
            return true;
        }
        if ((microtime(true) - $this->lastPersistTime) >= self::PERSIST_INTERVAL_SECONDS) {
            return true;
        }
        return false;
    }

    public function persist(): void
    {
        $this->save();

        $this->logger->debug('Persisted QoL learning state', [
            'samples' => $this->governor->getModel()->sampleCount(),
            'state' => $this->governor->getState()->value,
        ]);
    }

    /** Restore matching learning before worker admission, retaining a merge baseline. */
    public function restoreForWorker(): void
    {
        $this->withLock(function (): void {
            $fingerprint = $this->fingerprint->getName();
            $saved = $this->load();
            if (($saved['encoder_profile'] ?? null) !== $fingerprint) {
                $this->governor->resetLearning();
                $saved = null;
            } elseif (is_array($saved['governor'] ?? null)) {
                $this->governor->importState($saved['governor']);
            }
            $this->governor->activate();
            $this->workerFingerprint = $fingerprint;
            $this->workerRevision = is_string($saved['revision'] ?? null) ? $saved['revision'] : bin2hex(random_bytes(16));
            $this->workerSampleCount = $this->governor->getModel()->sampleCount();
            if (!is_string($saved['revision'] ?? null)) {
                $this->write([
                    'encoder_profile' => $fingerprint,
                    'revision' => $this->workerRevision,
                    'governor' => $this->governor->exportState(),
                ]);
            }
        });
    }

    /** Flushes even one worker sample; explicit control saves replace the learning revision. */
    public function save(): void
    {
        $this->withLock(function (): void {
            if ($this->workerRevision === null) {
                $this->write([
                    'encoder_profile' => $this->fingerprint->getName(),
                    'revision' => bin2hex(random_bytes(16)),
                    'governor' => $this->governor->exportState(),
                ]);
            } else {
                $this->mergeWorkerSamples();
            }
        });
        $this->sampleCounter = 0;
        $this->lastPersistTime = microtime(true);
    }

    private function mergeWorkerSamples(): void
    {
        $saved = $this->load();
        if (($saved['revision'] ?? null) !== $this->workerRevision
            || ($saved['encoder_profile'] ?? null) !== $this->workerFingerprint) {
            return;
        }
        $pending = array_slice($this->governor->getModel()->getState()['samples'], $this->workerSampleCount);
        if ($pending === []) {
            return;
        }
        $merged = new LearningModel();
        $model = $saved['governor']['model'] ?? [];
        if (!is_array($model)) {
            throw new RuntimeException('Saved worker learning model is invalid.');
        }
        $merged->restoreState($model);
        foreach ($pending as $sample) {
            $merged->addSample(UtilizationSample::fromArray($sample));
        }
        $state = ['state' => $merged->isReady() ? 'active' : 'learning', 'model' => $merged->getState()];
        $this->write(['encoder_profile' => $this->workerFingerprint, 'revision' => $this->workerRevision, 'governor' => $state]);
        $this->governor->importState($state);
        $this->workerSampleCount = $merged->sampleCount();
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $filePath = $this->stateFilePath();
        $temporary = sprintf('%s.%s.tmp', $filePath, bin2hex(random_bytes(6)));
        $json = $this->jsonEncoder->encode($data, 'json', [JsonEncode::OPTIONS => JSON_PRETTY_PRINT]);
        try {
            if (file_put_contents($temporary, $json) === false || !rename($temporary, $filePath)) {
                throw new RuntimeException(sprintf('Cannot save the QoL learning state to %s.', $filePath));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param \Closure(): void $operation */
    private function withLock(\Closure $operation): void
    {
        $lock = fopen($this->stateDir . '/governor_state.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the worker learning lock.');
        }
        try {
            $deadline = microtime(true) + 1.0;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Worker learning persistence lock timed out.');
                }
                Async::sleep(0.01);
            }
            try {
                $operation();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function stateFilePath(): string
    {
        return sprintf('%s/governor_state.json', $this->stateDir);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        $filePath = $this->stateFilePath();
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        return $this->jsonEncoder->decode($content, 'json');
    }

    public function cleanup(): void
    {
        $this->withLock(function (): void {
            $filePath = $this->stateFilePath();
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });
    }
}
