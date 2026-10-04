<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenTelemetry;

use App\Shared\Infrastructure\OpenTelemetry\InMemorySpanProcessor;
use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use PHPUnit\Framework\TestCase;

final class InMemorySpanProcessorTest extends TestCase
{
    public function testCompletedSpanCopiesAttributesThroughTheirDeclaredContract(): void
    {
        $values = [
            'code.filepath' => '/app/src/Shared/Example.php',
            'code.lineno' => 42,
            'http.method' => 'GET',
        ];
        $attributes = $this->createStubForIntersectionOfInterfaces([AttributesInterface::class, \Iterator::class]);
        $attributes->method('toArray')->willReturn($values);
        $attributes->method('get')->willReturnMap([
            ['code.filepath', $values['code.filepath']],
            ['code.lineno', $values['code.lineno']],
        ]);
        $data = $this->createStub(SpanDataInterface::class);
        $data->method('getAttributes')->willReturn($attributes);
        $data->method('getStartEpochNanos')->willReturn(100_000);
        $data->method('getEndEpochNanos')->willReturn(125_000);
        $data->method('getStatus')->willReturn(StatusData::ok());
        $span = $this->createStub(ReadableSpanInterface::class);
        $span->method('getContext')->willReturn(SpanContext::create(str_repeat('a', 32), str_repeat('b', 16)));
        $span->method('getParentContext')->willReturn(SpanContext::getInvalid());
        $span->method('getName')->willReturn('health');
        $span->method('toSpanData')->willReturn($data);
        $bridge = new SpanBridge();
        $bridge->clear();
        $processor = new InMemorySpanProcessor();
        $processor->setBridge($bridge);

        try {
            $processor->onEnd($span);

            $recorded = $bridge->getRecentSpans();
            self::assertCount(1, $recorded);
            self::assertSame($values, $recorded[0]['attributes']);
            self::assertSame('/app/src/Shared/Example.php', $recorded[0]['file_path']);
            self::assertSame(42, $recorded[0]['line_number']);
            self::assertSame(25, $recorded[0]['duration_us']);
        } finally {
            $bridge->clear();
        }
    }
}
