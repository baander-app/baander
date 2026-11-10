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
        $transfer->expire(true);
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

    public function testRejectedQuiescenceSettlesTheFailedTransferAndKeepsTheSourceAuthoritative(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $transfer->acknowledge('prepared');
        $transfer->acknowledge('rejected');
        self::assertSame('failed', $transfer->state());
        self::assertNull($transfer->command());
    }

    public function testRejectedStartSettlesTheFailedTransferWithoutStopProof(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $transfer->acknowledge('prepared');
        $transfer->acknowledge('quiesced', 14.25);
        $transfer->acknowledge('rejected');
        self::assertSame('failed', $transfer->state());
        self::assertNull($transfer->command());
        self::assertSame(14.25, $transfer->position());
    }

    public function testRejectedStopKeepsTheUncertainDestinationFenced(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $transfer->acknowledge('prepared');
        $transfer->acknowledge('quiesced', 14.25);
        $transfer->expire(true);
        $transfer->acknowledge('rejected');
        self::assertSame('uncertain', $transfer->state());
        self::assertSame('stop', $transfer->command());
    }

    public function testExpiryFailsATransferWhoseTargetWasNeverShownItsStart(): void
    {
        $quiescing = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $quiescing->acknowledge('prepared');
        $starting = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $starting->acknowledge('prepared');
        $starting->acknowledge('quiesced', 14.25);
        $uncertain = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
        $uncertain->acknowledge('prepared');
        $uncertain->acknowledge('quiesced', 14.25);
        $uncertain->expire(true);

        foreach ([$quiescing, $starting, $uncertain] as $transfer) {
            $transfer->expire(false);
            self::assertSame('failed', $transfer->state());
            self::assertNull($transfer->command());
        }
        self::assertSame(14.25, $starting->position());
    }

    public function testExpiryKeepsATransferUncertainOnceItsTargetWasShownTheStart(): void
    {
        $transfer = PlaybackTransfer::begin('attempt', null, 'target', 'playing', 12.5);
        $transfer->acknowledge('prepared');
        self::assertSame('start', $transfer->command());
        $transfer->expire(true);
        $transfer->expire(true);
        self::assertSame('uncertain', $transfer->state());
        self::assertSame('stop', $transfer->command());
        self::assertSame('target', $transfer->commandEndpoint());
    }

    public function testAnUnpreparedTransferFailsAtExpiryEitherWay(): void
    {
        foreach ([true, false] as $shown) {
            $transfer = PlaybackTransfer::begin('attempt', 'source', 'target', 'playing', 12.5);
            $transfer->expire($shown);
            self::assertSame('failed', $transfer->state());
        }
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
