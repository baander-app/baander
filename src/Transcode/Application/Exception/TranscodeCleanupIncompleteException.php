<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

use App\Shared\Application\Exception\ServiceUnavailableException;

/**
 * A cleanup run went through every orphaned job and could not remove some of them.
 *
 * The run did the rest of its work, and the next run takes up the jobs it left. HTTP answers
 * 503 with the run's counts as the error details; the console command prints the message and fails.
 */
final class TranscodeCleanupIncompleteException extends ServiceUnavailableException
{
    public function __construct(
        public readonly int $removed,
        public readonly int $failed,
        public readonly int $expired = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'The transcode job cleanup removed %d orphaned %s and could not remove %d; the log names each job that failed.',
                $removed,
                $removed === 1 ? 'job' : 'jobs',
                $failed,
            ),
            // The counts a finished run returns, and the number of jobs still to remove.
            ['expired' => $expired, 'cleaned' => $removed, 'failed' => $failed],
            $previous,
        );
    }

    /** The same outcome for a run that expired this many stale sessions before it removed jobs. */
    public function afterExpiring(int $expired): self
    {
        return new self($this->removed, $this->failed, $expired, $this);
    }
}
