<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

use App\Shared\Domain\Model\Uuid;

final readonly class WatchableTargets
{
    /**
     * @param list<WatchableTarget> $targets Unique owned targets by kind and UUID.
     * @param list<Uuid> $videoIds Every linked version of those targets, including unavailable versions.
     */
    public function __construct(public array $targets, public array $videoIds) {}
}
