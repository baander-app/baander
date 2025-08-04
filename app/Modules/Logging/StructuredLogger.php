<?php

namespace App\Modules\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

class StructuredLogger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $className,
        private readonly array $defaultContext = []
    ) {}

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $structuredContext = $this->enhanceContext($context);
        $this->logger->log($level, $message, $structuredContext);
    }

    private function enhanceContext(array $context): array
    {
        $enhanced = array_merge($this->defaultContext, $context);

        $enhanced['class'] = $this->className;

        return $enhanced;
    }
}