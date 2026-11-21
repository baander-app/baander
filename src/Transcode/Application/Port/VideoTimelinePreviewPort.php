<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Catalog\Application\Dto\VideoSourceMetadata;

interface VideoTimelinePreviewPort
{
    /** @return array<string, mixed> */
    public function describe(VideoSourceMetadata $source): array;

    public function image(VideoSourceMetadata $source): ?string;

    public function prepare(
        string $path,
        string $identity,
        float $duration,
        float $origin,
        string $sourceHash,
        int $videoStreamIndex,
    ): void;
}
