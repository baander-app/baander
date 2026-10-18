<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Exception\WorkerTransportConfigurationException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Keeps supplied Redis URLs from replacing the worker's streams or launch identity. */
#[Exclude]
final class WorkerTransportConfiguration
{
    /** @param array<array-key,mixed> $environment */
    public static function validate(#[SensitiveParameter] array $environment, bool $required = false): void
    {
        foreach (['REDIS_URL', 'MESSENGER_TRANSPORT_DSN'] as $key) {
            if (!array_key_exists($key, $environment)) {
                if ($required) {
                    throw new WorkerTransportConfigurationException($key . ' is required for worker transport configuration.');
                }
                continue;
            }
            $dsn = $environment[$key];
            if (!is_string($dsn) || $dsn === '' || preg_match('/[\x00-\x20\x7f,\\\\]/', $dsn) !== 0
                || substr_count($dsn, '@') > 1 || preg_match('/%(?![0-9a-fA-F]{2})/', $dsn) !== 0
            ) {
                throw new WorkerTransportConfigurationException($key . ' requires an unambiguous single Redis endpoint.');
            }
            $parts = parse_url($dsn);
            if ($parts === false || !in_array($parts['scheme'] ?? '', ['redis', 'rediss'], true)
                || !isset($parts['host']) || $parts['host'] === '' || str_contains($parts['host'], '%')
                || (isset($parts['port']) && $parts['port'] === 0) || isset($parts['fragment'])
            ) {
                throw new WorkerTransportConfigurationException($key . ' requires an unambiguous single Redis endpoint.');
            }
            if ($key === 'REDIS_URL') {
                if (isset($parts['path']) || isset($parts['query'])) {
                    throw new WorkerTransportConfigurationException('REDIS_URL must be pathless and queryless for the scheduler transport.');
                }
                continue;
            }
            if (!in_array($parts['path'] ?? '', ['', '/messages', '/messages/baander'], true)) {
                throw new WorkerTransportConfigurationException('MESSENGER_TRANSPORT_DSN must retain the messages stream, baander group and generated consumer.');
            }
            if (isset($parts['query'])) {
                self::validateQuery($parts['query']);
            }
        }
    }

    private static function validateQuery(#[SensitiveParameter] string $query): void
    {
        $seen = [];
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            // Symfony parse_str merges DSN options over configured options. Admit only
            // exact scalar keys; duplicates and nested/normalized keys are ambiguous.
            if (count($parts) !== 2 || isset($seen[$key]) || !self::isAllowedOption($key, $value)) {
                throw new WorkerTransportConfigurationException('MESSENGER_TRANSPORT_DSN query must use unique scalar connection options or the configured stream and group.');
            }
            $seen[$key] = true;
        }
    }

    private static function isAllowedOption(string $key, #[SensitiveParameter] string $value): bool
    {
        return match ($key) {
            'stream' => $value === 'messages',
            'group' => $value === 'baander',
            'dbindex', 'retry_interval' => preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false,
            'timeout', 'read_timeout' => preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) === 1
                && filter_var($value, FILTER_VALIDATE_FLOAT) !== false && is_finite((float) $value),
            default => false,
        };
    }
}
