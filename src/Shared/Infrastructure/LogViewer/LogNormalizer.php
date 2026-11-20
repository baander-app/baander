<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer;

use App\Shared\Infrastructure\Logging\StreamSignatureRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;

final readonly class LogNormalizer
{
    public const int MAX_RECORD_BYTES = 262144;

    public function __construct(private int $maxRecordBytes = self::MAX_RECORD_BYTES) {}

    /**
 * @param array<string, mixed> $metadata
 * @return array<string, mixed>
 */
    public function normalize(string $raw, array $metadata = []): array
    {
        $truncated = strlen($raw) > $this->maxRecordBytes;
        $raw = mb_strcut($raw, 0, $this->maxRecordBytes, 'UTF-8');
        $raw = mb_scrub($raw, 'UTF-8');
        $data = json_decode($raw, true);
        $message = rtrim($raw, "\r\n");
        $timestamp = $metadata['timestamp'] ?? null;
        $level = 'unknown';
        $channel = null;
        $context = $extra = [];
        $format = 'raw';
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            $format = 'json';
            $message = $data['message'];
            $timestamp = $data['datetime'] ?? $data['timestamp'] ?? $timestamp;
            $level = strtolower((string) ($data['level_name'] ?? $data['level'] ?? 'unknown'));
            $channel = is_string($data['channel'] ?? null) ? $data['channel'] : null;
            $context = is_array($data['context'] ?? null) ? $data['context'] : [];
            $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        } elseif (preg_match('/^\[([^\]]+)\]\s+([\w.-]+)\.([A-Z]+):\s*(.*)$/s', $message, $matches) === 1) {
            $format = 'monolog';
            [, $timestamp, $channel, $level, $message] = $matches;
            $level = strtolower($level);
            if (preg_match('/^(.*?) (\{[^\n]*\}|\[[^\n]*\]) (\{[^\n]*\}|\[[^\n]*\])$/s', $message, $parts) === 1) {
                $parsedContext = json_decode($parts[2], true);
                $parsedExtra = json_decode($parts[3], true);
                if (is_array($parsedContext) && is_array($parsedExtra)) {
                    $message = $parts[1];
                    $context = $parsedContext;
                    $extra = $parsedExtra;
                }
            }
        } elseif (preg_match('/^\[([^\]]+)\].*?\b(DEBUG|TRACE|INFO|NOTICE|WARNING|WARN|ERROR|CRITICAL|ALERT|EMERGENCY)\b[:\s]*(.*)$/s', $message, $matches) === 1) {
            $format = 'native';
            [, $timestamp, $level, $message] = $matches;
            $level = strtolower($level);
        }
        $level = ['warn' => 'warning', '100' => 'debug', '200' => 'info', '250' => 'notice', '300' => 'warning', '400' => 'error', '500' => 'critical', '550' => 'alert', '600' => 'emergency'][$level] ?? $level;
        try {
            $date = new \DateTimeImmutable(is_string($timestamp) && $timestamp !== '' ? $timestamp : 'now');
        } catch (\Exception) {
            $date = new \DateTimeImmutable();
        }
        $redactor = new StreamSignatureRedactionProcessor();
        $safe = $redactor(new LogRecord($date, $channel ?? 'unknown', Level::Info, $message, $context, $extra));
        $safeRaw = $redactor(new LogRecord($date, 'raw', Level::Info, $raw))->message;
        $correlation = $safe->context['correlationId'] ?? $safe->context['correlation_id'] ?? $safe->extra['correlationId'] ?? $safe->extra['correlation_id'] ?? null;
        return [
            'id' => '', 'sourceId' => $metadata['sourceId'] ?? '',
            'timestamp' => $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level, 'channel' => $channel, 'message' => $safe->message,
            'context' => $safe->context, 'extra' => $safe->extra, 'raw' => $safeRaw,
            'correlationId' => is_scalar($correlation) ? (string) $correlation : null,
            'node' => $metadata['node'] ?? null, 'service' => $metadata['service'] ?? null,
            'stream' => $metadata['stream'] ?? null, 'truncated' => $truncated, 'format' => $format,
        ];
    }
}
