<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

use App\Shared\Domain\Model\Uuid;

final readonly class WatchableVideo
{
    /** @param list<WatchableTarget> $targets Unique targets in the opened title's library played by this file. */
    public function __construct(public Uuid $videoId, public ?float $durationSeconds, public array $targets) {}
}
