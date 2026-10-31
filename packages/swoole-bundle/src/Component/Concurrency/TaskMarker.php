<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Concurrency;

use Closure;
use Swoole\Coroutine\Channel;
use Throwable;

/** @internal */
final readonly class TaskMarker
{
    /** @param Closure(): void $task */
    public function __construct(private Closure $task, private Channel $acknowledgement) {}

    public function run(): void
    {
        ($this->task)();
    }

    public function acknowledge(?Throwable $error): void
    {
        $this->acknowledgement->push($error ?? true);
    }
}
