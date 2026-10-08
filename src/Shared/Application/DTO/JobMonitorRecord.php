<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use App\Shared\Domain\Model\JobStatus;

/**
 * One job of the job monitor: every delivery of a message shares its job's record.
 *
 * The records of a job list and of the running jobs leave out the error detail and the
 * stored message, so their $exception and $data are null; a single job's record has them.
 */
final readonly class JobMonitorRecord
{
    /**
     * @param array<string, mixed>|null $exception the failure's message, file and line
     */
    public function __construct(
        public string $jobId,
        public ?string $name,
        public ?string $queue,
        public JobStatus $status,
        public ?int $progress,
        public int $attempt,
        public bool $retried,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $exceptionClass,
        public ?array $exception,
        /** The serialized message, which a retry dispatches again. */
        public ?string $data,
        public bool $dataTruncated,
        /** The database-generated run time of the current attempt, so the detail agrees with the duration sort. */
        public ?int $durationMicroseconds,
    ) {
    }
}
