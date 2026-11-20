<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\LogViewer;

use App\Shared\Infrastructure\LogViewer\LogNormalizer;
use PHPUnit\Framework\TestCase;

final class LogNormalizerTest extends TestCase
{
    public function testJsonPreservesFieldsAndRedactsRawAndStructuredSecrets(): void
    {
        $record = (new LogNormalizer())->normalize(json_encode(['datetime' => '2026-11-20T10:00:00Z', 'level_name' => 'ERROR', 'channel' => 'app', 'message' => 'GET /?token=secret', 'context' => ['correlation_id' => 'trace', 'url' => '/?sig=secret']], JSON_THROW_ON_ERROR));
        self::assertSame('error', $record['level']);
        self::assertSame('trace', $record['correlationId']);
        self::assertStringNotContainsString('secret', json_encode($record, JSON_THROW_ON_ERROR));
    }
    public function testTextMultilineAndNativeRecords(): void
    {
        $normalizer = new LogNormalizer();
        $record = $normalizer->normalize("[2026-11-20T10:00:00Z] app.WARNING: Failed\n  stack frame {} []");
        self::assertSame('warning', $record['level']);
        self::assertStringContainsString('stack frame', $record['message']);
        self::assertSame('warning', $normalizer->normalize('[2026-11-20 10:00:00] WARNING worker stopped')['level']);
        self::assertSame('unrecognized', $normalizer->normalize('unrecognized')['message']);
    }
    public function testRecordSizeIsBoundedAndReported(): void
    {
        $record = (new LogNormalizer(128))->normalize(str_repeat('x', 200));
        self::assertTrue($record['truncated']);
        self::assertLessThanOrEqual(128, strlen($record['raw']));
    }
}
