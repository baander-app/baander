<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Playback;

use App\Session\Domain\Model\Playback\PlaybackTransfer;
use PHPUnit\Framework\TestCase;

final class PlaybackTransferTest extends TestCase
{
    public function testTargetCannotStartUntilSourceReportsItsCursor(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        self::assertSame('prepare', $transfer->command());
        $transfer->acknowledge('prepared');
        self::assertSame('quiesce', $transfer->command());
        $transfer->acknowledge('quiesced', 14.25);
        self::assertSame('start', $transfer->command());
        self::assertSame(14.25, $transfer->position());
        $transfer->acknowledge('started');
        self::assertSame('committed', $transfer->state());
    }

    public function testMissingStartAcknowledgementNeverRestartsSource(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $transfer->acknowledge('prepared');
        $transfer->acknowledge('quiesced', 14.25);
        $transfer->expire();
        self::assertSame('uncertain', $transfer->state());
        self::assertSame('stop', $transfer->command());
        self::assertSame('target', $transfer->commandEndpoint());
        $transfer->acknowledge('stopped');
        self::assertSame('failed', $transfer->state());
    }

    public function testRejectionBeforeQuiescenceLeavesSourceUntouched(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'paused', 12.5);
        $transfer->acknowledge('rejected');
        self::assertSame('failed', $transfer->state());
        self::assertNull($transfer->command());
    }
    public function testInvalidQuiescenceCannotAdvanceTransfer(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $transfer->acknowledge('prepared');
        foreach ([null, -1.0, INF, NAN] as $cursor) {
            try {
                $transfer->acknowledge('quiesced', $cursor);
                self::fail('Invalid cursor must fail before transition.');
            } catch (\DomainException) {
                self::assertSame('quiescing', $transfer->state());
            }
        }
    }

}
