<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A Swoole timer a context runs inside a web server worker.
 *
 * A timer left in a stopping worker keeps its event loop running until Swoole kills the
 * worker, so the drain stops every implementation when the worker exits
 * (DrainWorkerExitSubscriber). A context that owns such a timer implements this interface;
 * the tag it carries is how the drain finds it, so Shared never names the context.
 */
#[AutoconfigureTag]
interface WorkerTimerInterface
{
    /** Clears the timer. Safe to call more than once, and when no timer runs. */
    public function stop(): void;
}
