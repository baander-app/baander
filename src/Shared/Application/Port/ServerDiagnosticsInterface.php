<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Diagnostics of the running web server, read through the server control channel
 * so the admin endpoints and the app:server:* commands report the same numbers.
 *
 * Per-worker reads list one row per HTTP worker that answered, name the workers
 * that did not answer in `missing_workers` and those whose read failed in
 * `worker_errors`.
 *
 * @phpstan-type WorkerError array{worker_id: int, error: string}
 * @phpstan-type PerWorker array{workers: list<array<string, mixed>>, missing_workers: list<int>, worker_errors: list<WorkerError>}
 */
interface ServerDiagnosticsInterface
{
    /** The most spans one read returns; the buffer holds as many. */
    public const int MAX_SPANS = 500;

    /**
     * Per-worker process figures, plus the Redis figures every worker shares.
     *
     * @return array{workers: list<array<string, mixed>>, missing_workers: list<int>, worker_errors: list<WorkerError>, redis: array<string, mixed>}
     *
     * @throws ServerControlException when no server runs or it cannot answer
     */
    public function stats(): array;

    /**
     * Per-worker coroutine and channel statistics.
     *
     * @return PerWorker
     *
     * @throws ServerControlException when no server runs or it cannot answer
     */
    public function coroutines(): array;

    /**
     * Server-wide worker pool statistics: HTTP, task and transcoding workers.
     *
     * @return array<string, mixed>
     *
     * @throws ServerControlException when no server runs or it cannot answer
     */
    public function workers(): array;

    /**
     * The most recent spans of the server-wide buffer, newest first.
     *
     * @param int $limit capped to 0..MAX_SPANS
     *
     * @return list<array<string, mixed>>
     *
     * @throws ServerControlException when no server runs or it cannot answer
     */
    public function spans(int $limit): array;

    /**
     * Empties the server-wide span buffer.
     *
     * @throws ServerControlException when no server runs or it cannot answer
     */
    public function clearSpans(): void;
}
