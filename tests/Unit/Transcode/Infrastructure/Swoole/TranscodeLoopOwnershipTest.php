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
        $ownership = new TranscodeLoopOwnership($this->createStub(TranscodeLoopLeaseInterface::class));

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
        $replacement = new TranscodeLoopOwnership($this->createStub(TranscodeLoopLeaseInterface::class));
        $old->markLost();
        $old->close();

        $replacement->assertOwned();

        self::assertNotSame($old, $replacement);
        self::assertTrue($replacement->isActive());
        self::assertFalse($replacement->isLost());
    }
}
