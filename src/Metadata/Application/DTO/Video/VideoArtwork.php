<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

use App\Shared\Domain\Model\Uuid;

final readonly class VideoArtwork
{
    /**
     * @param string $path provider URL, or an absolute sidecar path when a library is supplied
     */
    public function __construct(
        public string $path,
        public ?Uuid $libraryId = null,
    ) {}
}
