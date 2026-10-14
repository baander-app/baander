<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\StreamSignatureRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class StreamSignatureRedactionProcessorTest extends TestCase
{
    private const string SIGNATURE = 'e27974ef93f1f0dc56a648fc46182d6a8eb375510270fbae47a326ee0b3da172';

    public function testTheSignatureIsRemovedFromTheMessageContextAndExtra(): void
    {
        $url = '/api/transcode/job/segment?index=0&sig=' . self::SIGNATURE . '&exp=1792065607';
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'swoole.access_log',
            Level::Info,
            '127.0.0.1 - - "GET ' . $url . ' HTTP/1.1" 200 13',
            ['request' => ['uri' => 'https://baander.app' . $url], 'plain' => 'no signature here'],
            ['url' => $url, 'ip' => '127.0.0.1'],
        );

        $redacted = (new StreamSignatureRedactionProcessor())($record);

        $expected = '/api/transcode/job/segment?index=0&sig=[redacted]&exp=1792065607';
        self::assertSame('127.0.0.1 - - "GET ' . $expected . ' HTTP/1.1" 200 13', $redacted->message);
        self::assertSame(['request' => ['uri' => 'https://baander.app' . $expected], 'plain' => 'no signature here'], $redacted->context);
        self::assertSame(['url' => $expected, 'ip' => '127.0.0.1'], $redacted->extra);
        self::assertStringNotContainsString(self::SIGNATURE, json_encode($redacted->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testASignatureAsTheOnlyParameterIsRemovedAndOtherRecordsAreUntouched(): void
    {
        $processor = new StreamSignatureRedactionProcessor();
        $only = $processor(new LogRecord(new \DateTimeImmutable(), 'app', Level::Debug, 'GET /api/transcode/v/master.m3u8?sig=' . self::SIGNATURE));
        $other = $processor(new LogRecord(new \DateTimeImmutable(), 'app', Level::Debug, 'GET /api/catalog?design=modern&page=2', ['exp' => 42]));

        self::assertSame('GET /api/transcode/v/master.m3u8?sig=[redacted]', $only->message);
        self::assertSame('GET /api/catalog?design=modern&page=2', $other->message);
        self::assertSame(['exp' => 42], $other->context);
    }
}
