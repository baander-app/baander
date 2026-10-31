<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Concurrency;

use Swoole\Coroutine\Channel;

/** @internal */
final readonly class DrainMarker
{
    public function __construct(private Channel $acknowledgement) {}

    public function acknowledge(?string $failure): void
    {
        $this->acknowledgement->push($failure ?? true);
    }
}
