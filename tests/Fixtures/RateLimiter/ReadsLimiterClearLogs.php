<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\RateLimiter;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Puts a JSON stream handler on the application logger, so a test reads the limiter clear
 * records as they are written out rather than as the logger was called.
 */
trait ReadsLimiterClearLogs
{
    /** @return resource */
    private function captureLogs()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new JsonFormatter());
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($handler);

        return $stream;
    }

    /**
     * @param resource $stream
     *
     * @return list<array{actor: mixed, limiters: mixed}>
     */
    private function clearRecords($stream): array
    {
        rewind($stream);
        $records = [];
        foreach (explode("\n", trim((string) stream_get_contents($stream))) as $line) {
            $record = $line === '' ? null : json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($record) && $record['message'] === 'Rate limiter state cleared.') {
                $records[] = [
                    'actor' => $record['context']['actor'],
                    'limiters' => $record['context']['limiters'],
                ];
            }
        }

        return $records;
    }
}
