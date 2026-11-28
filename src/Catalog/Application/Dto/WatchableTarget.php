<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

use App\Shared\Domain\Model\Uuid;

final readonly class WatchableTarget
{
    /** @param 'episode'|'movie' $kind */
    public function __construct(public string $kind, public Uuid $id) {}
}
