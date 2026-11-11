<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

/** A cleanup run went through every orphaned job and could not remove some of them. */
final class TranscodeCleanupIncompleteException extends \RuntimeException
{
    public function __construct(
        public readonly int $removed,
        public readonly int $failed,
    ) {
        parent::__construct(sprintf(
            'The transcode job cleanup removed %d orphaned %s and could not remove %d; the log names each job that failed.',
            $removed,
            $removed === 1 ? 'job' : 'jobs',
            $failed,
        ));
    }
}
