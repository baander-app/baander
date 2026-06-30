<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Service\StreamGovernor;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Persists governor learning state to JSON files.
 * Follows the JobStatePersister pattern: file-per-entity, dual-throttle writes.
 */
final class LearningDataPersister
{
    private const int PERSIST_INTERVAL_SAMPLES = 10;
    private const float PERSIST_INTERVAL_SECONDS = 5.0;

    private int $sampleCounter = 0;
    private float $lastPersistTime = 0.0;

    public function __construct(
        private readonly StreamGovernor             $governor,
        private readonly HardwareCapabilitiesProber $prober,
        private readonly LoggerInterface            $logger,
        private readonly string                     $stateDir,
        private readonly JsonEncoder                $jsonEncoder,
    )
    {
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
        $data = [
            'encoder_profile' => $this->prober->getProfile()->getName(),
            'governor' => $this->governor->exportState(),
        ];
        $filePath = $this->stateFilePath();

        file_put_contents(
            $filePath,
            $this->jsonEncoder->encode($data, 'json', [JsonEncode::OPTIONS => JSON_PRETTY_PRINT]),
        );

        $this->sampleCounter = 0;
        $this->lastPersistTime = microtime(true);

        $this->logger->debug('Persisted QoL learning state', [
            'samples' => $this->governor->getModel()->sampleCount(),
            'state' => $this->governor->getState()->value,
        ]);
    }

    private function stateFilePath(): string
    {
        return sprintf('%s/governor_state.json', $this->stateDir);
    }

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
