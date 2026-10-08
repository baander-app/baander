<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\StreamGovernor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Persists governor learning state to JSON files.
 * Follows the JobStatePersister pattern: file-per-entity, dual-throttle writes.
 *
 * Every HTTP worker writes the same file, so each write goes to a temporary file
 * that is renamed into place. The saved state holds the learning state and model
 * only; the profile has its own file (AlgorithmProfileTable).
 */
final class LearningDataPersister
{
    private const int PERSIST_INTERVAL_SAMPLES = 10;
    private const float PERSIST_INTERVAL_SECONDS = 5.0;

    private int $sampleCounter = 0;
    private float $lastPersistTime = 0.0;

    public function __construct(
        private readonly StreamGovernor $governor,
        private readonly EncoderProfileFingerprintPortInterface $fingerprint,
        private readonly LoggerInterface $logger,
        private readonly string $stateDir,
        private readonly JsonEncoder $jsonEncoder,
    ) {
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
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

    /**
     * Writes the learning state now. Unlike persist() it does not log, so server
     * control operations, which must not touch pooled services, can call it.
     *
     * @throws RuntimeException when the state cannot be written
     */
    public function save(): void
    {
        $data = [
            'encoder_profile' => $this->fingerprint->getName(),
            'governor' => $this->governor->exportState(),
        ];
        $filePath = $this->stateFilePath();
        $temporary = sprintf('%s.%s.tmp', $filePath, bin2hex(random_bytes(6)));
        $json = $this->jsonEncoder->encode($data, 'json', [JsonEncode::OPTIONS => JSON_PRETTY_PRINT]);

        if (file_put_contents($temporary, $json) === false || !rename($temporary, $filePath)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException(sprintf('Cannot save the QoL learning state to %s.', $filePath));
        }

        $this->sampleCounter = 0;
        $this->lastPersistTime = microtime(true);
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
        $filePath = $this->stateFilePath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
