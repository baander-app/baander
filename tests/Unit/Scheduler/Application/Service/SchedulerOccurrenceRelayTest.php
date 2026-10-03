<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\Service;

use App\Scheduler\Application\DTO\SchedulerOccurrenceDispatchClaim;
use App\Scheduler\Application\Port\SchedulerOccurrenceDispatchStoreInterface;
use App\Scheduler\Application\Port\SchedulerOccurrencePublisherInterface;
use App\Scheduler\Application\Service\SchedulerOccurrenceRelay;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulerOccurrenceRelayTest extends TestCase
{
    #[DataProvider('failureModes')]
    public function testFailedFirstHandoffCannotStarveRestOfReservedBatch(string $mode): void
    {
        $claims = [new SchedulerOccurrenceDispatchClaim(Uuid::v7(), Uuid::v7()), new SchedulerOccurrenceDispatchClaim(Uuid::v7(), Uuid::v7())];
        $store = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $store->expects(self::once())->method('claimPending')->with(2, 60)->willReturn($claims);
        $receipts = [];
        $cause = new \RuntimeException('Fixture handoff uncertainty.');
        $store->expects(self::exactly($mode === 'send' ? 1 : 2))->method('markPublished')->willReturnCallback(
            static function (SchedulerOccurrenceDispatchClaim $claim) use (&$receipts, $claims, $mode, $cause): bool {
                $receipts[] = $claim;
                if ($claim === $claims[0]) {
                    if ($mode === 'receipt-error') {
                        throw $cause;
                    }
                    return false;
                }
                return true;
            },
        );
        $publisher = $this->createMock(SchedulerOccurrencePublisherInterface::class);
        $published = [];
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (Uuid $id) use (&$published, $claims, $mode, $cause): void {
                $published[] = $id;
                if ($id === $claims[0]->occurrenceId && $mode === 'send') {
                    throw $cause;
                }
            },
        );
        try {
            (new SchedulerOccurrenceRelay($store, $publisher))->dispatchPending(2, 60);
            self::fail('A partial batch failure must remain visible to its supervisor.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler occurrence dispatch failed for 1 of 2 reservations.', $error->getMessage());
            if ($mode === 'receipt-refused') {
                self::assertSame('Scheduler occurrence publication receipt was not accepted.', $error->getPrevious()?->getMessage());
            } else {
                self::assertSame($cause, $error->getPrevious());
            }
        }
        self::assertSame([$claims[0]->occurrenceId, $claims[1]->occurrenceId], $published);
        self::assertSame($mode === 'send' ? [$claims[1]] : $claims, $receipts);
    }

    /** @return iterable<string, array{string}> */
    public static function failureModes(): iterable
    {
        foreach (['send', 'receipt-error', 'receipt-refused'] as $mode) {
            yield $mode => [$mode];
        }
    }

    public function testUncertainReservationCommitNeverPublishes(): void
    {
        $store = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $error = new \RuntimeException('Fixture lost commit acknowledgment.');
        $store->expects(self::once())->method('claimPending')->willThrowException($error);
        $store->expects(self::never())->method('markPublished');
        $publisher = $this->createMock(SchedulerOccurrencePublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $this->expectExceptionObject($error);
        (new SchedulerOccurrenceRelay($store, $publisher))->dispatchPending();
    }
}
