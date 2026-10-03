<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Transcode\Infrastructure\Swoole\TranscodeLoopOwnership;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Infrastructure\Swoole\TranscodeLoopOwnershipLost;
use PHPUnit\Framework\TestCase;

final class TranscodeLoopOwnershipTest extends TestCase
{
    public function testFreshLoopOwnsItsLease(): void
    {
        $lease = $this->createStub(TranscodeLoopLeaseInterface::class);
        $lease->method('renew')->willReturn(true);
        $ownership = new TranscodeLoopOwnership($lease);

        self::assertTrue($ownership->renew(60));
        $ownership->assertOwned();

        self::assertTrue($ownership->isActive());
        self::assertFalse($ownership->isLost());
    }

    public function testObservedLossRemainsPermanentAfterRepeatedLossAndClosure(): void
    {
        $ownership = new TranscodeLoopOwnership($this->createStub(TranscodeLoopLeaseInterface::class));
        $ownership->markLost();
        $ownership->markLost();
        $ownership->close();

        self::assertFalse($ownership->isActive());
        self::assertTrue($ownership->isLost());
        $this->expectException(TranscodeLoopOwnershipLost::class);
        $ownership->assertOwned();
    }

    public function testClosureRejectsOwnershipWithoutInventingLeaseLoss(): void
    {
        $ownership = new TranscodeLoopOwnership($this->createStub(TranscodeLoopLeaseInterface::class));
        $ownership->close();
        $ownership->close();

        self::assertFalse($ownership->isActive());
        self::assertFalse($ownership->isLost());
        $this->expectException(TranscodeLoopOwnershipLost::class);
        $ownership->assertOwned();
    }

    public function testReplacementLoopHasIndependentOwnershipIdentity(): void
    {
        $old = new TranscodeLoopOwnership($this->createStub(TranscodeLoopLeaseInterface::class));
        $lease = $this->createStub(TranscodeLoopLeaseInterface::class);
        $lease->method('renew')->willReturn(true);
        $replacement = new TranscodeLoopOwnership($lease);
        $old->markLost();
        $old->close();

        self::assertTrue($replacement->renew(60));
        $replacement->assertOwned();

        self::assertNotSame($old, $replacement);
        self::assertTrue($replacement->isActive());
        self::assertFalse($replacement->isLost());
    }
}
