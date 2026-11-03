<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\ValueObject;

use App\QoL\Domain\ValueObject\EncoderWork;
use PHPUnit\Framework\TestCase;

final class EncoderWorkTest extends TestCase
{
    public function testCostIsCpuSecondsPerOutputSecondAsAShareOfTheHostCpus(): void
    {
        // Three CPUs per second of video on a sixteen-CPU host.
        self::assertEqualsWithDelta(18.75, (new EncoderWork(180.0, 60.0))->costPercent(16), 0.000001);
    }

    public function testCostIsUnknownBeforeOneSegmentOfOutput(): void
    {
        self::assertNull((new EncoderWork(4.0, EncoderWork::MIN_OUTPUT_SECONDS - 0.5))->costPercent(4));
        self::assertNotNull((new EncoderWork(4.0, EncoderWork::MIN_OUTPUT_SECONDS))->costPercent(4));
    }

    public function testNegativeWorkIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncoderWork(-1.0, 10.0);
    }
}
