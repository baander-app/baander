<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

interface VideoQualityChoicesPortInterface
{
    /** @return list<array{id: string, label: string, width: int, height: int}> */
    public function choices(?int $sourceWidth, ?int $sourceHeight): array;
}
