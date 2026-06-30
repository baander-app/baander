<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class GenerateRecommendationsCommand
{
    public const MODE_FULL = 'full';
    public const MODE_INCREMENTAL = 'incremental';

    public function __construct(
        private readonly string $mode,
        private readonly ?Uuid $userId = null,
    ) {
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function isFull(): bool
    {
        return $this->mode === self::MODE_FULL;
    }

    public function isIncremental(): bool
    {
        return $this->mode === self::MODE_INCREMENTAL;
    }
}
