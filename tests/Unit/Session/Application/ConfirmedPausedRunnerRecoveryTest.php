<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class ConfirmedPausedRunnerRecoveryTest extends TestCase
{
    public function testSnapshotReleasesConfirmedPausedRunnerAndRetainsMediaQueueAndCursor(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $track = new PublicId();
        $session = ListeningSession::create($owner, [$track->toString()], 0, 27.5);
        $session->control('load', new PlaybackMedia('song', $track), null, null, null, PlaybackState::Paused);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Paused, 27.5);
        $revision = $session->revision();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->with($session)->willReturn(true);
        $store->expects(self::once())->method('saveSession')->with($session);
        $store->method('snapshot')->willReturn([]);
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        (new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class)))
            ->execute($owner, PlaybackOperation::Snapshot, []);

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame([$track->toString()], $session->getQueue());
        self::assertSame($track->toString(), $session->media()?->publicId->toString());
        self::assertSame(27.5, $session->getPosition());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
    }

    public function testMissingDurableProofKeepsUnavailableRunnerAuthority(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 9);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Paused, 9);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->willReturn(false);
        $store->expects(self::never())->method('saveSession');
        $store->method('snapshot')->willReturn([]);
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        (new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class)))
            ->execute($owner, PlaybackOperation::Snapshot, []);

        self::assertSame($runner, $session->activeIncarnationId());
        self::assertSame(0, $session->revision());
        self::assertSame(9.0, $session->getPosition());
    }
}
