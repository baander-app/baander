<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\StreamGovernor;
use Psr\Log\LoggerInterface;
use Swoole\Timer;
use Throwable;

/**
 * Monitors CPU utilization every 5 seconds and tracks consecutive over-budget readings.
 *
 * If over-budget is sustained for 10 consecutive seconds (2 checks),
 * triggers emergency stream release of the highest-cost stream.
 *
 * Timer callback uses error_log() only — no pooled services.
 */
final class MidStreamMonitor
{
    private const int CHECK_INTERVAL_MS = 5000;
    private const int OVERBUDGET_THRESHOLD = 2;
    private int $consecutiveOverBudget = 0;

    public function __construct(
        private readonly StreamGovernor  $governor,
        private readonly CpuGpuSampler   $sampler,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * Start monitoring. Must be called from worker 0's onWorkerStarted.
     */
    public function startMonitoring(): void
    {
        $this->logger->info('MidStreamMonitor starting on worker 0');

        Timer::tick(self::CHECK_INTERVAL_MS, function (): void {
            $this->check();
        });
    }

    private function check(): void
    {
        try {
            if ($this->governor->getState() === GovernorState::Learning) {
                $this->consecutiveOverBudget = 0;
                return;
            }

            $utilization = $this->sampler->getLatest() ?? [];
            $cpuPercent = (float)($utilization['cpu_percent'] ?? 0.0);
            $budgetCap = $this->governor->getProfile()->budgetCap() * 100.0;

            if ($cpuPercent > $budgetCap) {
                $this->consecutiveOverBudget++;
                error_log(sprintf(
                    '[MidStreamMonitor] Over-budget: %.1f%% > %.1f%% cap (consecutive: %d)',
                    $cpuPercent, $budgetCap, $this->consecutiveOverBudget,
                ));

                if ($this->consecutiveOverBudget >= self::OVERBUDGET_THRESHOLD) {
                    $this->emergencyRelease();
                    $this->consecutiveOverBudget = 0;
                }
            } else {
                $this->consecutiveOverBudget = 0;
            }
        } catch (Throwable $e) {
            error_log(sprintf('[MidStreamMonitor] Check failed: %s', $e->getMessage()));
        }
    }

    /**
     * Emergency release: find and release the highest-cost active stream.
     */
    private function emergencyRelease(): void
    {
        $streams = $this->governor->getActiveStreams();
        if ($streams === []) {
            return;
        }

        // Sort by predictedCost descending
        usort($streams, static fn($a, $b): int => $b->predictedCost <=> $a->predictedCost);
        $victim = $streams[0];

        $this->governor->releaseStream($victim->jobId);

        error_log(sprintf(
            '[MidStreamMonitor] Emergency released stream %s (tier: %s, predicted: %.1f%%)',
            $victim->jobId->toString(),
            $victim->qualityTier,
            $victim->predictedCost,
        ));
    }
}
