<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

use App\Shared\Domain\Model\Uuid;

final readonly class VideoRequest
{
    /**
     * @param array<string, string> $externalIds title IDs keyed by provider, including IMDb
     * @param list<VideoSidecar> $sidecars
     */
    public function __construct(
        public VideoKind $kind,
        public string $title,
        public ?int $year = null,
        public array $externalIds = [],
        public string $ordering = 'aired',
        public ?Uuid $libraryId = null,
        public array $sidecars = [],
        public ?Uuid $titleId = null,
    ) {}
}
