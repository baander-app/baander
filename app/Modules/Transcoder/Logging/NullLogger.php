<?php

namespace App\Modules\Transcoder\Logging;

/**
 * Null logger that discards all log messages
 * Used as default when no logger is provided
 */
class NullLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
        // Discard
    }

    public function info(string $message, array $context = []): void
    {
        // Discard
    }

    public function warning(string $message, array $context = []): void
    {
        // Discard
    }

    public function error(string $message, array $context = []): void
    {
        // Discard
    }
}
