<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncMovieMessage
{
    public function __construct(
        public Uuid $movieId,
        public bool $forceUpdate = false,
    ) {}
}
