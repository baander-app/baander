<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

interface LiveChangeProjector
{
    /** Returns the number of committed inbox rows folded; zero also covers a busy projector. */
    public function project(): int;
}
