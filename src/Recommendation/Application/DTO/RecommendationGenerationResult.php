<?php

declare(strict_types=1);

namespace App\Recommendation\Application\DTO;

/**
 * The outcome of starting a recommendation job.
 *
 * In the web server the job goes to the CPU process pool and is still pending; elsewhere,
 * such as from the console, it ran in this process and completed or was cancelled.
 */
final readonly class RecommendationGenerationResult
{
    public const string EXECUTION_ASYNC = 'async';
    public const string EXECUTION_SYNC = 'sync';

    /**
     * @param string             $status the job's status when this process let go of it
     * @param array<string, int> $counts recommendations saved per strategy; empty for a pooled job
     */
    public function __construct(
        public string $jobId,
        public string $publicId,
        public string $mode,
        public string $status,
        public string $execution,
        public array $counts = [],
    ) {
    }

    public function isAsync(): bool
    {
        return $this->execution === self::EXECUTION_ASYNC;
    }
}
