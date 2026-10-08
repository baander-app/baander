<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

/** The HTTP workers of the running server, as seen from the current process. */
interface ServerWorkers
{
    /** The current process's HTTP worker ID, or null outside an HTTP worker of the running server. */
    public function currentHttpWorkerId(): ?int;

    /** @return list<int> */
    public function httpWorkerIds(): array;

    /**
     * Sends a pipe message to another HTTP worker.
     *
     * @param array<string, mixed> $message
     */
    public function send(array $message, int $workerId): bool;
}
