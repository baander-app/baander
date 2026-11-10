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
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfirmedStoppedRunnerRecoveryTest extends TestCase
{
    private Uuid $owner;
    private Uuid $runner;
    private Uuid $destination;
    private PublicId $track;

    protected function setUp(): void
    {
        $this->owner = new Uuid();
        $this->runner = new Uuid();
        $this->destination = new Uuid();
        $this->track = new PublicId();
    }

    public function testSnapshotReleasesAConfirmedStoppedRunnerAndKeepsMediaQueueCursorAndStoppedState(): void
    {
        $session = $this->stoppedSession();
        $revision = $session->revision();
        $store = $this->store($session);
        $store->expects(self::once())->method('retireConfirmedStoppedBrowser')->with($session)->willReturn(true);
        $store->expects(self::never())->method('retireConfirmedPausedBrowser');
        $store->expects(self::once())->method('saveSession')->with($session);

        $this->snapshot($store);

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame([$this->track->toString()], $session->getQueue());
        self::assertSame($this->track->toString(), $session->media()?->publicId->toString());
        self::assertSame(27.5, $session->getPosition());
        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('stopped', $session->observedState()->value);
    }

    public function testAStoppedRunnerTheStoreDoesNotConfirmAsGoneKeepsItsPlace(): void
    {
        $session = $this->stoppedSession();
        $revision = $session->revision();
        $store = $this->store($session);
        $store->expects(self::once())->method('retireConfirmedStoppedBrowser')->with($session)->willReturn(false);
        $store->expects(self::never())->method('saveSession');

        $this->snapshot($store);

        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame($this->destination, $session->selectedDestinationId());
        self::assertSame($revision, $session->revision());
        self::assertSame(27.5, $session->getPosition());
    }

    public function testACurrentTransferKeepsTheStoppedRunner(): void
    {
        $session = $this->stoppedSession();
        $revision = $session->revision();
        $store = $this->store($session, [
            'id' => new Uuid()->toString(), 'state' => 'preparing',
            'expires_at' => new DateTimeImmutable('+20 seconds')->format(DATE_ATOM),
        ]);
        $store->expects(self::never())->method('retireConfirmedStoppedBrowser');
        $store->expects(self::never())->method('saveSession');

        $this->snapshot($store);

        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
    }

    public function testAStopTheRunnerHasNotConfirmedNeverAsksForItsRetirement(): void
    {
        $session = $this->stoppedSession(confirmed: false);
        $store = $this->store($session);
        $store->expects(self::never())->method('retireConfirmedStoppedBrowser');
        $store->expects(self::never())->method('retireConfirmedPausedBrowser');
        $store->expects(self::never())->method('saveSession');

        $this->snapshot($store);

        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame('playing', $session->observedState()->value);
    }

    private function stoppedSession(bool $confirmed = true): ListeningSession
    {
        $session = ListeningSession::create($this->owner, [$this->track->toString()], 0, 27.5);
        $session->control('load', new PlaybackMedia('song', $this->track), null, null, null, PlaybackState::Playing);
        $session->commitDestination($this->runner, $this->destination, PlaybackState::Playing, 27.5);
        $session->control('stop', null, null, null, null, null);
        if ($confirmed) {
            $session->confirmControl(false, null);
        }
        return $session;
    }

    /** @param array<string,mixed>|null $transfer */
    private function store(ListeningSession $session, ?array $transfer = null): MockObject&PlaybackStoreInterface
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturn($transfer);
        $store->method('pendingControl')->willReturn(null);
        $store->method('snapshot')->willReturn([]);
        return $store;
    }

    private function snapshot(PlaybackStoreInterface $store): void
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class))
            ->execute($this->owner, PlaybackOperation::Snapshot, []);
    }
}
