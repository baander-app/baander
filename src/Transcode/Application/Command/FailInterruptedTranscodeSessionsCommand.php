<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * The server stopped the encoding loops of these jobs, as a drain does. Their live sessions have
 * nothing producing segments any more and end as failed, so the next request starts a new one.
 */
final readonly class FailInterruptedTranscodeSessionsCommand
{
    /** @param list<Uuid> $jobIds */
    public function __construct(
        private array $jobIds,
    ) {
    }

    /** @return list<Uuid> */
    public function getJobIds(): array { return $this->jobIds; }
}
