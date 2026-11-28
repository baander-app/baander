<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Shared\Domain\Model\Uuid;

final readonly class VideoMatchTarget
{
    /** @param list<array<string, mixed>> $parses */
    public function __construct(
        public Uuid $id,
        public VideoRequest $request,
        public array $parses,
        public bool $matched,
    ) {}

    public function hasTitleNfo(): bool
    {
        return array_any($this->request->sidecars, static fn (VideoSidecar $sidecar): bool => in_array($sidecar->kind, [VideoSidecarKind::SeriesNfo, VideoSidecarKind::MovieNfo], true));
    }
}
