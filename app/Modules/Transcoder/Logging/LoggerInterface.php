<?php

namespace App\Modules\Transcoder\Logging;

/**
 * Logger interface for the transcoder client
 */
interface LoggerInterface
{
    /**
     * Log a debug message
     *
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log an info message
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log a warning message
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log an error message
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}
