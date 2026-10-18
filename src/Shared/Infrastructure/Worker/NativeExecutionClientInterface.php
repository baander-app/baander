<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

interface NativeExecutionClientInterface
{
    /** @return array<string, mixed> */
    public function handshake(): array;

    /** @param list<string> $argv */
    public function submit(array $argv, float $executionTimeoutSeconds = 600, ?int $attempt = null): NativeExecutionHandle;

    /** @return array<string, mixed> */
    public function inspect(NativeExecutionHandle $handle): array;

    /** @return array<string, mixed> */
    public function signal(NativeExecutionHandle $handle, string $signal): array;

    public function release(NativeExecutionHandle $handle): bool;
}
