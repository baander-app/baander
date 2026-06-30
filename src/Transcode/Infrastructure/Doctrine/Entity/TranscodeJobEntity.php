<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transcode_jobs')]
#[ORM\UniqueConstraint(name: 'transcode_jobs_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'transcode_jobs_video_id_quality_tier_name_key', columns: ['video_id', 'quality_tier_name'])]
class TranscodeJobEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $videoId;

    #[ORM\Column(type: 'text')]
    private string $qualityTierName;

    #[ORM\Column(type: 'text', options: ['default' => 'pending'])]
    private string $status;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $referenceCount;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalSegments;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $completedSegments;

    #[ORM\Column(type: 'text', options: ['default' => ''])]
    private string $outputDirectory;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $initSegmentPath;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $segmentMap;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $probeData;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $videoCodec;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $audioCodec;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $videoBitrate;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $audioBitrate;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $width;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $height;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $framerate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failReason = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $measuredLoudness;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $audioTrackLanguages;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $audioSegmentMap;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        Uuid $videoId,
        string $qualityTierName,
        string $status = 'pending',
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->videoId = $videoId;
        $this->qualityTierName = $qualityTierName;
        $this->status = $status;
        $this->referenceCount = 0;
        $this->totalSegments = 0;
        $this->completedSegments = 0;
        $this->outputDirectory = '';
        $this->initSegmentPath = null;
        $this->segmentMap = [];
        $this->probeData = [];
        $this->videoCodec = null;
        $this->audioCodec = null;
        $this->videoBitrate = 0;
        $this->audioBitrate = 0;
        $this->width = 0;
        $this->height = 0;
        $this->framerate = 0.0;
        $this->measuredLoudness = [];
        $this->audioTrackLanguages = [];
        $this->audioSegmentMap = [];
        $this->failReason = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getVideoId(): Uuid { return $this->videoId; }
    public function getQualityTierName(): string { return $this->qualityTierName; }
    public function getStatus(): string { return $this->status; }
    public function getReferenceCount(): int { return $this->referenceCount; }
    public function getTotalSegments(): int { return $this->totalSegments; }
    public function getCompletedSegments(): int { return $this->completedSegments; }
    public function getOutputDirectory(): string { return $this->outputDirectory; }
    public function getInitSegmentPath(): ?string { return $this->initSegmentPath; }
    public function getSegmentMap(): array { return $this->segmentMap; }
    public function getProbeData(): array { return $this->probeData; }
    public function getVideoCodec(): ?string { return $this->videoCodec; }
    public function getAudioCodec(): ?string { return $this->audioCodec; }
    public function getVideoBitrate(): int { return $this->videoBitrate; }
    public function getAudioBitrate(): int { return $this->audioBitrate; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getFramerate(): float { return $this->framerate; }
    public function getFailReason(): ?string { return $this->failReason; }
    public function getMeasuredLoudness(): array { return $this->measuredLoudness; }
    public function getAudioTrackLanguages(): array { return $this->audioTrackLanguages; }
    public function getAudioSegmentMap(): array { return $this->audioSegmentMap; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setReferenceCount(int $count): void
    {
        $this->referenceCount = $count;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setTotalSegments(int $total): void
    {
        $this->totalSegments = $total;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setCompletedSegments(int $completed): void
    {
        $this->completedSegments = $completed;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setOutputDirectory(string $directory): void
    {
        $this->outputDirectory = $directory;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setInitSegmentPath(?string $path): void
    {
        $this->initSegmentPath = $path;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setSegmentMap(array $map): void
    {
        $this->segmentMap = $map;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setProbeData(array $data): void
    {
        $this->probeData = $data;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setVideoCodec(?string $codec): void
    {
        $this->videoCodec = $codec;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAudioCodec(?string $codec): void
    {
        $this->audioCodec = $codec;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setVideoBitrate(int $bitrate): void
    {
        $this->videoBitrate = $bitrate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAudioBitrate(int $bitrate): void
    {
        $this->audioBitrate = $bitrate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setWidth(int $width): void
    {
        $this->width = $width;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setHeight(int $height): void
    {
        $this->height = $height;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setFramerate(float $framerate): void
    {
        $this->framerate = $framerate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setFailReason(?string $reason): void
    {
        $this->failReason = $reason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setMeasuredLoudness(array $loudness): void
    {
        $this->measuredLoudness = $loudness;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAudioTrackLanguages(array $languages): void
    {
        $this->audioTrackLanguages = $languages;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAudioSegmentMap(array $map): void
    {
        $this->audioSegmentMap = $map;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
