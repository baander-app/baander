<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenTelemetry;

use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class SpanBridgeTest extends TestCase
{
    public function testAFullRingKeepsTheNewestSpansInOrder(): void
    {
        $bridge = new SpanBridge();
        $bridge->boot();
        $bridge->clear();
        $written = SpanBridge::MAX_SPANS + 50;

        try {
            for ($i = 0; $i < $written; $i++) {
                $bridge->addSpan(['n' => $i]);
            }

            $spans = $bridge->getRecentSpans(SpanBridge::MAX_SPANS + 10);
            self::assertSame(SpanBridge::MAX_SPANS, $bridge->count());
            self::assertSame(
                range($written - 1, $written - SpanBridge::MAX_SPANS, -1),
                array_column($spans, 'n'),
            );
        } finally {
            $bridge->clear();
        }
    }

    public function testASpanTooLargeForItsSlotIsDroppedAndLoggedOnce(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        $bridge = new SpanBridge($logger);
        $bridge->boot();
        $bridge->clear();

        try {
            $bridge->addSpan(['n' => 1]);
            $bridge->addSpan(['n' => 2, 'big' => str_repeat('x', 20_000)]);
            $bridge->addSpan(['n' => 3, 'big' => str_repeat('x', 20_000)]);
            $bridge->addSpan(['n' => 4]);

            self::assertSame([4, 1], array_column($bridge->getRecentSpans(), 'n'));
            self::assertCount(1, $logger->records);
            self::assertSame('warning', $logger->records[0][0]);
        } finally {
            $bridge->clear();
        }
    }
}
