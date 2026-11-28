<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use DateTimeImmutable;

final readonly class VideoProviderPause
{
    public function __construct(
        public string $provider,
        public string $failureKind,
        public int $failureCount,
        public DateTimeImmutable $retryAt,
    ) {}
}
