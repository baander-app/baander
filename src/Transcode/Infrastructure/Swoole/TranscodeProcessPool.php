<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Dispatches source probes and subtitle extraction to the owned media helper. */
final class TranscodeProcessPool
{
    public function __construct(
        private readonly CpuProcessPoolInterface $pool,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->pool->isRunning();
    }

    public function probeVideo(TranscodeJob $job, string $sourcePath, int $generation): string
    {
        $key = sprintf('probe_video:%s:%d', $job->getId()->toString(), $generation);
        $this->pool->dispatch($this->jsonEncoder->encode(['type' => 'probe_video', 'source_path' => $sourcePath], 'json'), $key);
        return $key;
    }

    public function extractSubtitles(
        TranscodeJob $job,
        string $sourcePath,
        string $language,
        string $outputPath,
        int $generation,
    ): string {
        $key = sprintf('extract_subtitles:%s:%d:%s', $job->getId()->toString(), $generation, $language);
        $payload = $this->jsonEncoder->encode([
            'type' => 'extract_subtitles',
            'source_path' => $sourcePath,
            'output_path' => $outputPath,
            'language' => $language,
        ], 'json');

        $this->logger->debug('Dispatching subtitle extraction to pool', [
            'jobId' => $job->getId()->toString(),
            'language' => $language,
        ]);

        $this->pool->dispatch($payload, $key);
        return $key;
    }

    /**
     * Read and remove a worker result from the underlying pool.
     *
     * @return array{data: string, status: string}|null
     */
    public function readResult(string $key): ?array
    {
        return $this->pool->readResult($key);
    }
}
