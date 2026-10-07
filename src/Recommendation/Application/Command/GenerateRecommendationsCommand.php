<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Regenerates recommendation snapshots.
 *
 * Migration Version20261007130000 schedules it daily with `automatic: true`, so that run
 * generates only while `recommendations.auto_generate` is on. `app:recommendations:generate`,
 * the admin action and admin-created schedules leave `automatic` false and always generate.
 */
final readonly class GenerateRecommendationsCommand implements SchedulableCommandInterface
{
    public const MODE_FULL = 'full';
    public const MODE_INCREMENTAL = 'incremental';

    public function __construct(
        private string $mode = self::MODE_FULL,
        private ?Uuid $userId = null,
        private bool $automatic = false,
    ) {
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
