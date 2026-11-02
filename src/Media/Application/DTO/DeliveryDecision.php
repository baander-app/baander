<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

use App\Transcode\Application\DTO\CompletedVideoArtifact;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class DeliveryDecision
{
    public function __construct(
        public DeliveryKind $kind,
        public ?string $reason = null,
        public ?DeliverySelection $selection = null,
        public ?string $url = null,
        public ?string $requestId = null,
        public ?string $jobId = null,
        public ?CompletedVideoArtifact $artifact = null,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($this->kind, $this->reason, $this->selection, $url, $this->requestId, $this->jobId, $this->artifact);
    }

    public function withRequestId(string $requestId): self
    {
        return new self($this->kind, $this->reason, $this->selection, $this->url, $requestId, $this->jobId, $this->artifact);
    }
}
