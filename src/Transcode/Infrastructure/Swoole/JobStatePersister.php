<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeJobState;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Persists active transcode job state to disk on each segment completion.
 *
 * On graceful restart, reads state files and verifies segments exist on disk
 * before returning the deserialized state for resumption.
 */
final class JobStatePersister
{
    /**
     * @param string $stateDir Absolute path to the state directory
     */
    public function __construct(
        private readonly TranscodeJobRepositoryInterface $jobRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly LoggerInterface $logger,
        private readonly string $stateDir,
        private readonly JsonEncoder $jsonEncoder,
    ) {
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }
    }

    /**
     * Persist the state of an active transcode job to disk.
     */
    public function persist(TranscodeJob $job): void
    {
        if (!in_array($job->getStatus(), [TranscodeStatus::InProgress], true)) {
            return;
        }

        $state = $job->getState();
        $data = [
            'jobId' => $state->id->toString(),
            'videoId' => $state->videoId->toString(),
            'qualityTier' => $state->qualityTierName,
            'completedSegments' => array_map(
                static fn(string $index, array $info): array => ['index' => $index, 'path' => $info['path']],
                array_keys($state->segmentMap),
                array_values($state->segmentMap),
            ),
            'totalSegments' => $state->totalSegments,
            'currentSegmentIndex' => count($state->segmentMap),
            'status' => $state->status->value,
            'startedAt' => $state->createdAt->format(\DateTimeInterface::ATOM),
            'audioProfile' => null,
        ];

        $filePath = $this->stateFilePath($job->getPublicId());
        file_put_contents($filePath, $this->jsonEncoder->encode($data, 'json', [JsonEncode::OPTIONS => JSON_PRETTY_PRINT]));

        $this->logger->debug('Persisted job state', [
            'jobId' => $state->id->toString(),
            'completedSegments' => count($state->segmentMap),
        ]);
    }

    /**
     * Load persisted state for a job, verifying segments exist on disk.
     *
     * @return array{jobId: string, videoId: string, qualityTier: string, completedSegments: int[], totalSegments: int, currentSegmentIndex: int, status: string}|null
     */
    public function load(PublicId $jobPublicId): ?array
    {
        $filePath = $this->stateFilePath($jobPublicId);

        if (!file_exists($filePath)) {
            return null;
        }

        $data = $this->jsonEncoder->decode(file_get_contents($filePath), 'json');

        $existingSegments = array_filter(
            $data['completedSegments'] ?? [],
            fn(array $segment): bool => $this->storage->exists($segment['path']),
        );

        $data['completedSegments'] = array_map(
            static fn(array $segment): string => $segment['index'],
            array_values($existingSegments),
        );
        $data['currentSegmentIndex'] = count($existingSegments);

        return $data;
    }

    /**
     * List all persisted state files.
     *
     * @return list<string> List of job public IDs with persisted state
     */
    public function listPersistedJobs(): array
    {
        $files = glob($this->stateDir . '/*.json');
        if ($files === false) {
            return [];
        }

        return array_map(static fn(string $file) => basename($file, '.json'), $files);
    }

    /**
     * Remove the persisted state file after job completion.
     */
    public function cleanup(PublicId $jobPublicId): void
    {
        $filePath = $this->stateFilePath($jobPublicId);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    private function stateFilePath(PublicId $jobPublicId): string
    {
        return sprintf('%s/%s.json', $this->stateDir, $jobPublicId->toString());
    }
}
