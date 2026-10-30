<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class LivePipelineHealth
{
    public function __construct(public ?int $oldestPendingAgeMs) {}

    public function isDegraded(): bool
    {
        return ($this->oldestPendingAgeMs ?? 0) > 5000;
    }
}
