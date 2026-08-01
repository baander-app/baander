<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Logger;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;

/**
 * Resets Monolog loggers by closing all their handlers.
 *
 * Monolog clones handler instances for each logger channel, causing
 * file descriptor accumulation. This resetter closes all handlers
 * on each logger when workers reload.
 */
final class MonologLoggerResetter implements Resetter
{
    public function reset(object $service): void
    {
        if ($service instanceof Logger) {
            $service->close();
        }
    }
}
