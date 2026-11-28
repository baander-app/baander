<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface WatchMembershipPortInterface
{
    /** Current membership or host authority in an active party playing this exact video. */
    public function isWatching(Uuid $memberId, Uuid $videoId): bool;
}
