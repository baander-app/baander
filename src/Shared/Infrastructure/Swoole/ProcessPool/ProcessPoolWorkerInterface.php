<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\ProcessPool;

/**
 * Contract for CPU pool worker handlers.
 *
 * Implementations are tagged `swoole.cpu_pool_worker` and auto-discovered by
 * CpuProcessPool. Each handler declares its supported job types and processes
 * serialized payloads in an isolated process (no container, no coroutines).
 */
interface ProcessPoolWorkerInterface
{
    /**
     * The job type strings this handler supports.
     *
     * @return list<string>
     */
    public function supportedTypes(): array;

    /**
     * Handle a single job in the pool worker process.
     *
     * Runs in an isolated process — no coroutine context, no Symfony container.
     * Keep stateless: receive serialized input, return serialized output.
     *
     * @param string $payload Serialized job data
     * @return string Serialized result
     */
    public function handle(string $payload): string;
}
