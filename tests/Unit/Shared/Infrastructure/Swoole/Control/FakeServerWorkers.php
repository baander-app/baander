<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Shared\Infrastructure\Swoole\Control\ServerWorkers;
use Closure;

/** HTTP workers of a simulated server; send() delivers pipe messages between them. */
final class FakeServerWorkers implements ServerWorkers
{
    /** @var list<array{to: int, message: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<int> workers that receive requests but never reply */
    public array $silent = [];

    /** @var list<int> workers a message cannot be sent to */
    public array $undeliverable = [];

    public ?int $current;

    /** @param Closure(array<string, mixed>, int, int): void $deliver */
    public function __construct(int $current, private readonly int $size, private readonly Closure $deliver)
    {
        $this->current = $current;
    }

    public function currentHttpWorkerId(): ?int
    {
        return $this->current;
    }

    public function httpWorkerIds(): array
    {
        return range(0, $this->size - 1);
    }

    public function send(array $message, int $workerId): bool
    {
        if (in_array($workerId, $this->undeliverable, true)) {
            return false;
        }
        $this->sent[] = ['to' => $workerId, 'message' => $message];
        if (!in_array($workerId, $this->silent, true)) {
            ($this->deliver)($message, (int) $this->current, $workerId);
        }

        return true;
    }
}
