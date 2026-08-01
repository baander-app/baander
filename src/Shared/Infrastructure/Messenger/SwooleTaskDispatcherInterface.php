<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;

interface SwooleTaskDispatcherInterface
{
    public function dispatchTask(Envelope $envelope): bool;
}
