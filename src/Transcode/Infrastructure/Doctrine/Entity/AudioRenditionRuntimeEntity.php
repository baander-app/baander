<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audio_rendition_runtime')]
#[ORM\Index(name: 'idx_audio_rendition_runtime_pending', columns: ['updated_at', 'rendition_key'], options: ['where' => "(state = ANY (ARRAY['pending'::text, 'running'::text]))"])]
#[ORM\UniqueConstraint(name: 'uniq_audio_rendition_canonical', columns: ['track_key', 'source_signature', 'format', 'bitrate'])]
final class AudioRenditionRuntimeEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'rendition_key', type: 'text')]
    private string $renditionKey;

    #[ORM\Column(name: 'track_key', type: 'text')]
    private string $trackKey;

    #[ORM\Column(name: 'source_signature', type: 'text')]
    private string $sourceSignature;

    #[ORM\Column(name: 'source_path', type: 'text')]
    private string $sourcePath;

    #[ORM\Column(name: 'format', type: 'text')]
    private string $format;

    #[ORM\Column(name: 'bitrate', type: 'integer')]
    private int $bitrate;

    #[ORM\Column(name: 'output_directory', type: 'text')]
    private string $outputDirectory;

    #[ORM\Column(name: 'state', type: 'text')]
    private string $state;

    #[ORM\Column(name: 'generation', type: 'integer')]
    private int $generation;

    #[ORM\Column(name: 'partial_path', type: 'text', nullable: true)]
    private ?string $partialPath;

    #[ORM\Column(name: 'final_path', type: 'text', nullable: true)]
    private ?string $finalPath;

    #[ORM\Column(name: 'error', type: 'text', nullable: true)]
    private ?string $error;

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
