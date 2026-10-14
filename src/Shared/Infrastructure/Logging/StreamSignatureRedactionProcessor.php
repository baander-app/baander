<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes the signature of a signed delivery URL from every log record.
 *
 * The signature is a bearer capability for the media until the URL expires, and a request
 * URL reaches the log through the web processor's `url` field, the access log line and any
 * message or context value that quotes a request. The `sig` query value is replaced wherever
 * it appears; the rest of the URL, including `exp`, is kept. Runs after the web processor.
 */
final class StreamSignatureRedactionProcessor implements ProcessorInterface
{
    private const string PATTERN = '/([?&]sig=)[^&\s"\'<>\\\\]+/';
    private const string REPLACEMENT = '$1[redacted]';

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->redactString($record->message);
        $context = $this->redact($record->context);
        $extra = $this->redact($record->extra);
        if ($message === $record->message && $context === $record->context && $extra === $record->extra) {
            return $record;
        }

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) || is_array($value)) {
                $redacted = is_string($value) ? $this->redactString($value) : $this->redact($value);
                if ($redacted !== $value) {
                    $values[$key] = $redacted;
                }
            }
        }

        return $values;
    }

    private function redactString(string $value): string
    {
        if (!str_contains($value, 'sig=')) {
            return $value;
        }

        return preg_replace(self::PATTERN, self::REPLACEMENT, $value) ?? $value;
    }
}
