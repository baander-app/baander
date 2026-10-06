<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

// Updates/deletes belong to the revision-checking DBAL repository, never managed-entity flush.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'scheduled_jobs')]
#[ORM\Index(name: 'idx_scheduled_jobs_status', columns: ['status'])]
#[ORM\Index(name: 'idx_scheduled_jobs_next_run_at', columns: ['next_run_at'])]
#[ORM\Index(name: 'idx_scheduled_jobs_recovery_after_id', columns: ['recovery_after', 'id'], options: ['where' => "(status = 'active'::text)"])]
class ScheduledJobEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $revision;

    // Materializer-owned: NULL until the committed configuration is first observed.
    // Align reverse introspection with this project's timestamptz mapping while retaining native TZ DDL.
    #[ORM\Column(type: 'datetime_immutable', nullable: true, columnDefinition: 'TIMESTAMPTZ DEFAULT NULL')]
    private ?\DateTimeImmutable $evaluatedThrough = null;

    // Generated from the database clock, then owned by durable recovery selection.
    #[ORM\Column(
        type: 'datetime_immutable',
        insertable: false,
        updatable: false,
        options: ['default' => 'clock_timestamp()'],
        columnDefinition: 'TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp() CHECK (isfinite(recovery_after))',
        generated: 'INSERT')]
    private \DateTimeImmutable $recoveryAfter;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $expression;

    #[ORM\Column(type: 'text')]
    private string $jobType;

    #[ORM\Column(type: 'text')]
    private string $command;

    #[ORM\Column(type: 'text', options: ['default' => 'active'])]
    private string $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var array<array-key, mixed> */
    // Native JSON preserves numeric lexemes and argument order used by PHP invocation.
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $parameters = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResult = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $runCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFailureAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function __construct(Uuid $id)
    {
        $this->id = $id;
        $this->revision = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecoveryAfter(): \DateTimeImmutable
    {
        return $this->recoveryAfter;
    }

    public function getEvaluatedThrough(): ?\DateTimeImmutable
    {
        return $this->evaluatedThrough;
    }

    public function getRevision(): Uuid
    {
        return $this->revision;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function setExpression(string $expression): void
    {
        $this->expression = $expression;
    }

    public function getJobType(): string
    {
        return $this->jobType;
    }

    public function setJobType(string $jobType): void
    {
        $this->jobType = $jobType;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command): void
    {
        $this->command = $command;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /** @return array<array-key, mixed> */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /** @param array<array-key, mixed> $parameters */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): void
    {
        $this->lastRunAt = $lastRunAt;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): void
    {
        $this->nextRunAt = $nextRunAt;
    }

    public function getLastResult(): ?string
    {
        return $this->lastResult;
    }

    public function setLastResult(?string $lastResult): void
    {
        $this->lastResult = $lastResult;
    }

    public function getRunCount(): int
    {
        return $this->runCount;
    }

    public function setRunCount(int $runCount): void
    {
        $this->runCount = $runCount;
    }

    public function getLastFailureAt(): ?\DateTimeImmutable
    {
        return $this->lastFailureAt;
    }

    public function setLastFailureAt(?\DateTimeImmutable $lastFailureAt): void
    {
        $this->lastFailureAt = $lastFailureAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }
}
