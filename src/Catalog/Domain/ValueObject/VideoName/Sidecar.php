<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject\VideoName;

final readonly class Sidecar
{
    public function __construct(
        public SidecarKind $kind,
        public ?int $seasonNumber = null,
        public ?string $videoStem = null,
    ) {}
}
