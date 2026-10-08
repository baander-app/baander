<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Regenerates recommendation snapshots as a recommendation job.
 *
 * Migration Version20261007130000 schedules it daily with `automatic: true`, so that run
 * generates only while `recommendations.auto_generate` is on. `app:recommendation:generate`,
 * the admin action and admin-created schedules leave `automatic` false and always generate.
 *
 * Without a job ID the handler creates the job record; RequeueRecommendationJobHandler
 * passes the pending job it created so that job runs.
 */
final readonly class GenerateRecommendationsCommand implements SchedulableCommandInterface
{
    public const MODE_FULL = 'full';
    public const MODE_INCREMENTAL = 'incremental';

    public function __construct(
        private string $mode = self::MODE_FULL,
        private ?Uuid $userId = null,
        private bool $automatic = false,
        private ?string $actor = null,
        private ?Uuid $jobId = null,
    ) {
    }

    /** Who asked for the run: the admin's user identifier or Actor::CLI; null for the scheduler. */
    public function getActor(): ?string
    {
        return $this->actor;
    }

    /** The pending job record to run instead of creating one. */
    public function getJobId(): ?Uuid
    {
        return $this->jobId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function isFull(): bool
    {
        return $this->mode === self::MODE_FULL;
    }

    public function isIncremental(): bool
    {
        return $this->mode === self::MODE_INCREMENTAL;
    }

    /** True for the automatic schedule, which yields to the recommendations.auto_generate setting. */
    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    public static function schedulerDescription(): string
    {
        return 'Regenerate recommendation snapshots.';
    }

    public static function schedulerParameters(): array
    {
        return [
            'mode' => [
                'type' => 'string',
                'required' => false,
                'description' => '"full" regenerates every song; "incremental" only songs updated in the last 7 days',
                'default' => self::MODE_FULL,
            ],
            'automatic' => [
                'type' => 'bool',
                'required' => false,
                'description' => 'true skips the run while the recommendations.auto_generate setting is off',
                'default' => false,
            ],
        ];
    }
}
