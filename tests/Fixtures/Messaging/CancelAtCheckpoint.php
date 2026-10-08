<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;

/** A cancellation checkpoint whose job is cancelled after a number of passed checkpoints. */
final class CancelAtCheckpoint implements JobCancellationCheckpointInterface
{
    public int $checks = 0;

    public function __construct(
        private readonly int $passes = PHP_INT_MAX,
    ) {
    }

    public function check(): void
    {
        if (++$this->checks > $this->passes) {
            throw JobCancelledException::forJob('cancelled-job');
        }
    }
}
