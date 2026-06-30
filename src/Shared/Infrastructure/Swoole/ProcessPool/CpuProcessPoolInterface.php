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
    public function dispatch(string $payload, string $resultKey): void;

    public function getResultTable(): ?Table;
}
