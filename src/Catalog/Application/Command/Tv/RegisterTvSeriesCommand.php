<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Tv;

use App\Shared\Domain\Model\Uuid;

final readonly class RegisterTvSeriesCommand
{
    public function __construct(
        public Uuid $libraryId,
        public int $tmdbId,
        public bool $forceUpdate = false,
    ) {}
}
