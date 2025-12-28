<?php

namespace App\Modules\Transcoder\Logging;

/**
 * Console logger that outputs to stderr/stdout
 */
class ConsoleLogger implements LoggerInterface
{
    private const string RESET = "\033[0m";
    private const string RED = "\033[31m";
    private const string YELLOW = "\033[33m";
    private const string BLUE = "\033[34m";
    private const string GRAY = "\033[90m";

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context, self::GRAY);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(
        string $level,
        string $message,
        array  $context,
        string $color,
    ): void
    {
        $timestamp = date('H:i:s.v');
        $contextStr = $this->formatContext($context);

        $output = sprintf(
            "%s[%s] [%s]%s %s %s\n",
            $color,
            $timestamp,
            $level,
            self::RESET,
            $message,
            $contextStr,
        );

        // Write to stderr for logs
        fwrite(STDERR, $output);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, $this->formatValue($value));
        }

        return sprintf('{%s}', implode(', ', $parts));
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->formatValue(...), $value)) . ']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string)$value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context, self::BLUE);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context, self::YELLOW);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context, self::RED);
    }
}
