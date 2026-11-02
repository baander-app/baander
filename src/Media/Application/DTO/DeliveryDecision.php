<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class DeliveryDecision
{
    public function __construct(
        public DeliveryKind $kind,
        public ?string $reason = null,
        public ?DeliverySelection $selection = null,
        public ?string $url = null,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($this->kind, $this->reason, $this->selection, $url);
    }
}
