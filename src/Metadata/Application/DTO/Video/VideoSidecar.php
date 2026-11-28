<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

use App\Shared\Domain\Model\Uuid;

final readonly class VideoSidecar
{
    /**
     * @param string $path absolute candidate path for the guarded library reader
     */
    public function __construct(
        public VideoSidecarKind $kind,
        public string $path,
        public ?int $seasonNumber = null,
        public ?string $videoStem = null,
        public ?Uuid $videoId = null,
    ) {}
}
