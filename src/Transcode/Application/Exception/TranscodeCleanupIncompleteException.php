<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

/**
 * A cleanup run went through every orphaned job and could not remove some of them.
 *
 * The console command and the admin endpoint both end with it, so it carries every
 * count the run produced.
 */
final class TranscodeCleanupIncompleteException extends \RuntimeException
{
    public function __construct(
        public readonly int $removed,
        public readonly int $failed,
        public readonly int $expired = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'The transcode job cleanup removed %d orphaned %s and could not remove %d; the log names each job that failed.',
            $removed,
            $removed === 1 ? 'job' : 'jobs',
            $failed,
        ), previous: $previous);
    }

    /** The same outcome for a run that expired this many stale sessions before it removed jobs. */
    public function afterExpiring(int $expired): self
    {
        return new self($this->removed, $this->failed, $expired, $this);
    }
}
