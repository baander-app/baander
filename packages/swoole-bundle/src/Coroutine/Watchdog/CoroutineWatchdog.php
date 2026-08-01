<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Coroutine\Watchdog;

use Swoole\Coroutine;

/**
 * Detects stalled coroutines by checking elapsed execution time.
 * Designed to be called periodically via Swoole timer tick.
 *
 * IMPORTANT: This class MUST NOT use pooled services (including the Monolog logger).
 * The watchdog runs on a Swoole timer tick — if it tries to acquire a pooled logger
 * instance while the pool is exhausted (the very scenario it's trying to report),
 * it creates a deadlock: watchdog → pooled logger → service pool → mutex timeout → fatal.
 *
 * All logging goes through error_log() directly, bypassing the service pool entirely.
 */
final class CoroutineWatchdog
{
    /**
     * Set of coroutine IDs that have already been logged as "long-lived".
     * Prevents repeated INFO logs on every tick for the same coroutine.
     *
     * @var array<int, true>
     */
    private array $reportedLongLived = [];

    /**
     * Number of consecutive checks where a coroutine's stack was unchanged
     * before we consider it truly stalled (not just long-lived).
     *
     * Key: coroutine ID, Value: [stack_hash => string, count => int].
     *
     * @var array<int, array{hash: string, count: int}>
     */
    private array $stallTracking = [];

    /**
     * @param int $stallThresholdMs Milliseconds before a coroutine enters stall detection (default: 30000 = 30s)
     * @param int $stallConfirmChecks Consecutive unchanged-stack checks before confirming a true stall (default: 3)
     *   At 5s tick interval, 3 checks = 15s confirmation window after initial threshold
     */
    public function __construct(
        private readonly int $stallThresholdMs = 30_000,
        private readonly int $stallConfirmChecks = 3,
    ) {}

    /**
     * Check all active coroutines for stalls. Call on each timer tick.
     *
     * Detection strategy:
     * 1. Coroutines below threshold — ignored.
     * 2. Coroutines above threshold, top frame is Coroutine::sleep — logged once as
     *    "long-lived sleeping" (INFO), then suppressed. Sleeping is intentional waiting.
     * 3. Coroutines above threshold, not sleeping — tracked via stack hash. If the
     *    stack is unchanged for stallConfirmChecks consecutive ticks, confirmed as stalled (WARNING).
     */
    public function check(): void
    {
        $coroutines = Coroutine::list();
        $activeCids = [];

        foreach ($coroutines as $cId) {
            $activeCids[$cId] = true;
            $elapsedMs = Coroutine::getElapsed($cId);

            if ($elapsedMs <= $this->stallThresholdMs) {
                continue;
            }

            $backtrace = $this->getSafeBacktrace($cId);

            // Check if coroutine is intentionally long-lived (sleeping, streaming, etc.)
            if ($this->isIntentionalLongLived($cId)) {
                if (!isset($this->reportedLongLived[$cId])) {
                    $this->reportedLongLived[$cId] = true;
                    $this->log('info', 'Long-lived I/O coroutine', [
                        'coroutine_id' => $cId,
                        'duration_ms' => $elapsedMs,
                        'duration_sec' => round($elapsedMs / 1000, 1),
                        'stack_trace' => $backtrace,
                    ]);
                }

                // Clear any stall tracking — intentional I/O is not stalled
                unset($this->stallTracking[$cId]);

                continue;
            }

            // Not sleeping — track stack for stall detection
            $stackHash = md5($backtrace);

            if (!isset($this->stallTracking[$cId])) {
                // First check above threshold for a non-sleeping coroutine
                $this->stallTracking[$cId] = ['hash' => $stackHash, 'count' => 0];
                $this->reportedLongLived[$cId] = true;

                $this->log('info', 'Long-lived coroutine detected', [
                    'coroutine_id' => $cId,
                    'duration_ms' => $elapsedMs,
                    'duration_sec' => round($elapsedMs / 1000, 1),
                    'stack_trace' => $backtrace,
                ]);

                continue;
            }

            if ($this->stallTracking[$cId]['hash'] === $stackHash) {
                ++$this->stallTracking[$cId]['count'];

                if ($this->stallTracking[$cId]['count'] >= $this->stallConfirmChecks) {
                    $this->log('warning', 'Coroutine stall confirmed', [
                        'coroutine_id' => $cId,
                        'duration_ms' => $elapsedMs,
                        'duration_sec' => round($elapsedMs / 1000, 1),
                        'stall_checks' => $this->stallTracking[$cId]['count'],
                        'threshold_ms' => $this->stallThresholdMs,
                        'stack_trace' => $backtrace,
                    ]);
                }
            } else {
                // Stack changed — coroutine is long-lived but active, reset
                $this->stallTracking[$cId] = ['hash' => $stackHash, 'count' => 0];
            }
        }

        // Clean up tracking for exited coroutines
        foreach (array_keys($this->reportedLongLived) as $cId) {
            if (!isset($activeCids[$cId])) {
                unset($this->reportedLongLived[$cId], $this->stallTracking[$cId]);
            }
        }
    }

    /**
     * Check if a coroutine is performing intentional long-lived I/O
     * (sleeping, streaming a file, serving a WebSocket connection, etc.).
     * These are legitimate long-lived operations — not stalls.
     */
    private function isIntentionalLongLived(int $cId): bool
    {
        try {
            $trace = Coroutine::getBackTrace($cId);
            if ($trace === false || $trace === []) {
                return false;
            }

            // Check top frame for known intentional patterns
            $topFrame = $trace[0];
            $function = ($topFrame['class'] ?? '') . ($topFrame['type'] ?? '') . ($topFrame['function'] ?? '');

            // Coroutine::sleep — SSE heartbeat, keepalive, etc.
            if ($function === 'Swoole\\Coroutine::sleep') {
                return true;
            }

            // Swoole\Http\Response->write — file streaming (audio, video, downloads)
            if ($function === 'Swoole\\Http\\Response->write') {
                return $this->isStreamingResponse($trace);
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if the response->write() call is inside a known streaming handler
     * (StreamedResponseProcessor, not arbitrary code).
     */
    private function isStreamingResponse(array $trace): bool
    {
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (str_contains($file, 'StreamedResponseProcessor.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a log entry directly via error_log(), bypassing the service pool.
     *
     * Format: [swoole-watchdog] LEVEL: message {"context":"json"}
     * This ensures watchdog output appears in Docker/stderr even when
     * the Monolog service pool is exhausted.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $line = sprintf('[swoole-watchdog] %s: %s %s', strtoupper($level), $message, $contextJson);
        error_log($line);
    }

    /**
     * Safely retrieve backtrace — may fail for coroutines that exit during iteration.
     */
    private function getSafeBacktrace(int $cId): string
    {
        try {
            $trace = Coroutine::getBackTrace($cId);
            if ($trace === false) {
                return '(coroutine exited during inspection)';
            }

            return $this->formatBacktrace($trace);
        } catch (\Throwable $e) {
            return sprintf('(backtrace unavailable: %s)', $e->getMessage());
        }
    }

    /**
     * @param array<array{file?: string, line?: int, function?: string, class?: string, type?: string}> $trace
     */
    private function formatBacktrace(array $trace): string
    {
        $lines = [];
        foreach (array_slice($trace, 0, 10) as $frame) {
            $file = $frame['file'] ?? '(internal)';
            $line = $frame['line'] ?? 0;
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $lines[] = sprintf('%s:%d %s', $file, $line, $function);
        }

        return implode("\n", $lines);
    }
}
