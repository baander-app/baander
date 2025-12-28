<?php

namespace App\Modules\Transcoder\Logging;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger adapter
 * Wraps a PSR-3 compliant logger (like Monolog)
 */
class Psr3Logger implements LoggerInterface
{
    private const array LEVEL_MAP = [
        'debug'   => LogLevel::DEBUG,
        'info'    => LogLevel::INFO,
        'warning' => LogLevel::WARNING,
        'error'   => LogLevel::ERROR,
    ];

    public function __construct(
        private readonly PsrLoggerInterface $logger,
        private readonly ?string            $prefix = null,
    )
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->prefix !== null) {
            $message = '[' . $this->prefix . '] ' . $message;
        }

        $this->logger->log($level, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }
}
