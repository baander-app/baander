<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\StreamAllocation;
use Psr\Log\LoggerInterface;
use Swoole\Timer;
use Throwable;

/**
 * Checks the governed streams' own encoder cost every 5 seconds and tracks consecutive
 * over-budget readings.
 *
 * The load is the sum of each active stream's measured cost (its encoder CPU seconds
 * per output second against the host's CPUs), or its predicted cost until it has
 * reported one. Host-wide utilization plays no part, so other encodes saturating the
 * host do not count against this child's stream.
 *
 * If over-budget is sustained for 10 consecutive seconds (2 checks),
 * triggers emergency stream release of the highest-cost stream.
 *
 * Timer callback uses error_log() only — no pooled services. The governor and the
 * profile table it reads are not pooled either, so the tick needs no
 * CoWrapper::defer(). Add one before the tick uses the logger or a repository.
 */
final class MidStreamMonitor
{
    private const int CHECK_INTERVAL_MS = 5000;
    private const int OVERBUDGET_THRESHOLD = 2;
    private int $consecutiveOverBudget = 0;
    private ?int $timerId = null;

    public function __construct(
        private readonly StreamGovernor  $governor,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * Start monitoring in the process that owns stream execution.
     */
    public function startMonitoring(): void
    {
        if ($this->timerId !== null) {
            return;
        }
        $this->logger->info('MidStreamMonitor starting in the execution process');

        $timerId = Timer::tick(self::CHECK_INTERVAL_MS, function (): void {
            $this->check();
        });
        // Native Swoole may return false; usable timer ids are positive.
        if ($timerId < 1) {
            error_log('[MidStreamMonitor] Worker 0 could not start the monitoring timer');

            return;
        }
        $this->timerId = $timerId;
    }

    /**
     * Stops the monitoring timer, which would otherwise keep a stopping worker's event loop
     * running. Safe to call more than once.
     */
    public function stopMonitoring(): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
    }

    /** One budget check; the timer runs it every five seconds. */
    public function check(): void
    {
        try {
            if ($this->governor->getState() === GovernorState::Learning) {
                $this->consecutiveOverBudget = 0;
                return;
            }

            $load = $this->governor->observedLoad();
            $budgetCap = $this->governor->getProfile()->budgetCap() * 100.0;

            if ($load > $budgetCap) {
                $this->consecutiveOverBudget++;
                error_log(sprintf(
                    '[MidStreamMonitor] Over-budget: %.1f%% > %.1f%% cap (consecutive: %d)',
                    $load, $budgetCap, $this->consecutiveOverBudget,
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

        usort($streams, static fn(StreamAllocation $a, StreamAllocation $b): int => $b->cost() <=> $a->cost());
        $victim = $streams[0];

        $this->governor->releaseStream($victim->jobId);

        error_log(sprintf(
            '[MidStreamMonitor] Emergency released stream %s (tier: %s, cost: %.1f%%)',
            $victim->jobId->toString(),
            $victim->qualityTier,
            $victim->cost(),
        ));
    }
}
