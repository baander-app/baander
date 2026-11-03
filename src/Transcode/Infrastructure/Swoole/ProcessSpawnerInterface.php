<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

/** Owns native attempts without exposing execution-container PIDs or descriptors. */
interface ProcessSpawnerInterface
{
    /** @param list<string> $command */
    public function spawn(array $command): mixed;

    /**
     * The attempt's outcome so far; cpuSeconds is the encoder's cumulative CPU time.
     *
     * @return array{state: string, stdout: string, stderr: string, exitCode: ?int, error: ?string, cpuSeconds: float}
     */
    public function status(mixed $handle): array;

    public function signal(mixed $handle, string $operation): void;

    /**
     * Release only after terminal process-tree exit has been confirmed.
     *
     * @return float the exited attempt's total CPU seconds
     */
    public function close(mixed $handle): float;
}
