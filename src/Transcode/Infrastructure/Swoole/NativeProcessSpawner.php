<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;

/** Executes and retires captured native attempts in the execution unit. */
final class NativeProcessSpawner implements ProcessSpawnerInterface
{
    public function __construct(private readonly NativeExecutionClientInterface $client) {}

    public function spawn(array $command): NativeExecutionHandle
    {
        return $this->client->submit($command, 86400);
    }

    public function status(mixed $handle): array
    {
        $status = $this->client->inspect($this->handle($handle));
        if (!in_array($status['state'] ?? null, ['running', 'exited'], true)
            || !is_string($status['stdout'] ?? null) || !is_string($status['stderr'] ?? null)
            || (!is_int($status['exitCode'] ?? null) && ($status['exitCode'] ?? null) !== null)
            || (!is_string($status['error'] ?? null) && ($status['error'] ?? null) !== null)
        ) {
            throw new NativeExecutionException('Native video attempt has no confirmed outcome.');
        }
        return ['state' => $status['state'], 'stdout' => $status['stdout'], 'stderr' => $status['stderr'],
            'exitCode' => $status['exitCode'] ?? null, 'error' => $status['error'] ?? null];
    }

    public function signal(mixed $handle, string $operation): void
    {
        $result = $this->client->signal($this->handle($handle), $operation);
        if (!in_array($result['state'] ?? null, ['running', 'exited'], true)) {
            throw new NativeExecutionException('Native video signal has no confirmed outcome.');
        }
    }

    public function close(mixed $handle): void
    {
        $handle = $this->handle($handle);
        $deadline = hrtime(true) + 1_000_000_000;
        do {
            $status = $this->status($handle);
            if ($status['state'] === 'exited') {
                if (!$this->client->release($handle)) {
                    throw new NativeExecutionException('Native video attempt release is unconfirmed.');
                }
                return;
            }
            Async::sleep(0.01);
        } while (hrtime(true) < $deadline);
        throw new NativeExecutionException('Native video process-tree exit is unconfirmed.');
    }

    private function handle(mixed $handle): NativeExecutionHandle
    {
        if (!$handle instanceof NativeExecutionHandle) {
            throw new NativeExecutionException('Native video attempt handle is invalid.');
        }
        return $handle;
    }
}
