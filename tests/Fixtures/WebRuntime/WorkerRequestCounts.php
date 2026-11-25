<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\WebRuntime;

use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugStatsOperation;
use RuntimeException;

/**
 * One reading of the requests every HTTP worker has received, through the fan-out behind
 * `app:server:stats`, and the comparison that shows whether a group of requests reached
 * every worker.
 *
 * Fixed dispatch pins a connection to one worker, so a group of requests can miss a worker
 * without any answer showing it. The counts are the evidence that it did not.
 */
final readonly class WorkerRequestCounts
{
    /**
     * A reading is itself one request on the worker that accepts its control connection, and
     * Swoole counts it after that worker has reported. The next reading therefore shows one
     * request more on that worker even when no HTTP request reached it.
     */
    private const int REQUESTS_OF_A_READING = 1;

    /** @param array<int, array{pid: int, received: int}> $workers by worker ID */
    public function __construct(public array $workers)
    {
    }

    public static function read(ServerControlPortInterface $server): self
    {
        $result = $server->execute(DebugStatsOperation::NAME);
        if (!$result->isComplete()) {
            throw new RuntimeException(sprintf(
                'Not every worker reported its request count: no answer from %s, errors from %s.',
                json_encode($result->missingWorkers, JSON_THROW_ON_ERROR),
                json_encode(array_keys($result->errors), JSON_THROW_ON_ERROR),
            ));
        }
        $workers = [];
        foreach ($result->results as $id => $stats) {
            $pid = $stats['process']['pid'] ?? null;
            $received = $stats['requests']['received'] ?? null;
            if (!is_int($pid) || !is_int($received)) {
                throw new RuntimeException(sprintf('Worker %d reported no request count.', $id));
            }
            $workers[$id] = ['pid' => $pid, 'received' => $received];
        }

        return new self($workers);
    }

    /**
     * Why $later does not show that every worker served an HTTP request since this reading,
     * one reason per worker; empty when it shows it. No other reading may lie between the two.
     *
     * @return list<string>
     */
    public function workersNotShownToHaveServed(self $later): array
    {
        $reasons = [];
        foreach (array_keys($this->workers + $later->workers) as $id) {
            $then = $this->workers[$id] ?? null;
            $now = $later->workers[$id] ?? null;
            if ($then === null || $now === null) {
                $reasons[] = sprintf('worker %d reported in one reading only', $id);
            } elseif ($then['pid'] !== $now['pid']) {
                $reasons[] = sprintf('worker %d was replaced (PID %d, then %d)', $id, $then['pid'], $now['pid']);
            } elseif ($now['received'] - $then['received'] <= self::REQUESTS_OF_A_READING) {
                $reasons[] = sprintf(
                    'the request count of worker %d went from %d to %d, which the reading before can account for',
                    $id,
                    $then['received'],
                    $now['received'],
                );
            }
        }

        return $reasons;
    }
}
