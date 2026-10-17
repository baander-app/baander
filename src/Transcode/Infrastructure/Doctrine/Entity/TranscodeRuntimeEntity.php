<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'transcode_runtime_state')]
#[ORM\Index(name: 'idx_transcode_runtime_state_pending', columns: ['updated_at', 'job_id'], options: ['where' => "(outcome = ANY (ARRAY['pending'::text, 'accepted'::text, 'ready'::text]))"])]
#[ORM\Index(name: 'idx_transcode_runtime_state_session_id', columns: ['session_id'])]
final class TranscodeRuntimeEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'job_id', type: 'uuid')]
    private Uuid $jobId;

    #[ORM\Column(name: 'session_id', type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Column(name: 'request_id', type: 'text')]
    private string $requestId;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'desired', type: 'json', options: ['jsonb' => true])]
    private array $desired;

    #[ORM\Column(name: 'desired_revision', type: 'integer')]
    private int $desiredRevision;

    #[ORM\Column(name: 'observed_revision', type: 'integer')]
    private int $observedRevision;

    #[ORM\Column(name: 'generation', type: 'integer')]
    private int $generation;

    #[ORM\Column(name: 'outcome', type: 'text')]
    private string $outcome;

    #[ORM\Column(name: 'output_directory', type: 'text', nullable: true)]
    private ?string $outputDirectory;

    #[ORM\Column(name: 'error', type: 'text', nullable: true)]
    private ?string $error;

    #[ORM\Column(name: 'admission_deadline', type: 'datetime_immutable')]
    private \DateTimeImmutable $admissionDeadline;

    #[ORM\Column(name: 'worker_namespace', type: 'text', nullable: true)]
    private ?string $workerNamespace;

    #[ORM\Column(name: 'worker_boot_id', type: 'text', nullable: true)]
    private ?string $workerBootId;

    #[ORM\Column(name: 'worker_id', type: 'text', nullable: true)]
    private ?string $workerId;

    #[ORM\Column(name: 'worker_launch_generation', type: 'integer', nullable: true)]
    private ?int $workerLaunchGeneration;

    #[ORM\Column(name: 'worker_lease_epoch', type: 'integer', nullable: true)]
    private ?int $workerLeaseEpoch;

    #[ORM\Column(name: 'claim_token', type: 'text', nullable: true)]
    private ?string $claimToken;

    #[ORM\Column(name: 'lease_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

}
