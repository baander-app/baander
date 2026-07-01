<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'job_monitors')]
class JobMonitorEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $jobUuid = null;

    #[ORM\Column(type: 'text')]
    private string $jobId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $queue = null;

    #[ORM\Column(type: 'job_status', options: ['default' => 'queued'])]
    private JobStatus $status = JobStatus::Queued;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $queuedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempt = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $retried = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $progress = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $exception = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $data = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $dataTruncated = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $auditLog = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $jobId,
        ?string $jobUuid = null,
        ?string $name = null,
        ?string $queue = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->jobId = $jobId;
        $this->jobUuid = $jobUuid;
        $this->name = $name;
        $this->queue = $queue;
        $this->queuedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getJobUuid(): ?string
    {
        return $this->jobUuid;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getQueue(): ?string
    {
        return $this->queue;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function setStatus(JobStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getQueuedAt(): ?\DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function markStarted(): void
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->status = JobStatus::Running;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function markFinished(): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = JobStatus::Finished;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markFailed(): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = JobStatus::Failed;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = JobStatus::Cancelled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function incrementAttempt(): void
    {
        $this->attempt++;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isRetried(): bool
    {
        return $this->retried;
    }

    public function markRetried(): void
    {
        $this->retried = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function setProgress(?int $progress): void
    {
        $this->progress = $progress;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getException(): ?array
    {
        return $this->exception;
    }

    public function setException(?array $exception): void
    {
        $this->exception = $exception;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function setExceptionClass(?string $exceptionClass): void
    {
        $this->exceptionClass = $exceptionClass;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function setData(?string $data): void
    {
        $this->data = $data;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDataTruncated(): bool
    {
        return $this->dataTruncated;
    }

    public function setDataTruncated(bool $dataTruncated): void
    {
        $this->dataTruncated = $dataTruncated;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAuditLog(): ?string
    {
        return $this->auditLog;
    }

    public function setAuditLog(?string $auditLog): void
    {
        $this->auditLog = $auditLog;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
