<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\ProcessPool;

use Swoole\Table;

/**
 * Abstraction over CpuProcessPool for injection into handlers.
 * CpuProcessPool is final — use this interface when mocking in tests.
 */
interface CpuProcessPoolInterface
{
    /** Whether the pool accepts work in this process (false outside the Swoole server). */
    public function isRunning(): bool;

    public function dispatch(string $payload, string $resultKey): void;

    public function getResultTable(): ?Table;

    /**
     * Read and remove a worker result.
     *
     * @return array{data: string, status: string}|null
     */
    public function readResult(string $key): ?array;
}
