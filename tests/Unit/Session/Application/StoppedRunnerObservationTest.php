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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StoppedRunnerObservationTest extends TestCase
{
    public function testTheActiveRunnerReportingItStoppedReleasesTheSessionAtItsCursor(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $track = new PublicId();
        $session = ListeningSession::create($owner, [$track->toString()], 0, 27.5);
        $session->control('load', new PlaybackMedia('song', $track), null, null, null, PlaybackState::Playing);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Playing, 27.5);
        $revision = $session->revision();
        $store = $this->store($session, $runner);
        $store->expects(self::once())->method('observe')->with($runner->toString(), 'stopped', 31.5, null);
        $store->expects(self::once())->method('saveSession')->with($session);

        $this->coordinator($store)->execute(
            $owner,
            PlaybackOperation::Observe,
            ['incarnationId' => $runner->toString(), 'expectedRevision' => $revision, 'observedState' => 'stopped', 'position' => 31.5],
            'capability',
        );

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame(31.5, $session->getPosition());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame($track->toString(), $session->media()?->publicId->toString());
    }

    public function testAPausedObservationKeepsTheRunner(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 9);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Playing, 9);
        $revision = $session->revision();
        $store = $this->store($session, $runner);
        $store->expects(self::once())->method('observe')->with($runner->toString(), 'paused', 12.25, null);
        $store->expects(self::once())->method('saveSession')->with($session);

        $this->coordinator($store)->execute(
            $owner,
            PlaybackOperation::Observe,
            ['incarnationId' => $runner->toString(), 'expectedRevision' => $revision, 'observedState' => 'paused', 'position' => 12.25],
            'capability',
        );

        self::assertSame($runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
        self::assertSame(12.25, $session->getPosition());
        self::assertSame('paused', $session->observedState()->value);
    }

    public function testAStopReportFromAnotherEndpointLeavesTheRunnerAlone(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $bystander = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 9);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Playing, 9);
        $revision = $session->revision();
        $store = $this->store($session, $bystander);
        $store->expects(self::never())->method('saveSession');

        $this->coordinator($store)->execute(
            $owner,
            PlaybackOperation::Observe,
            ['incarnationId' => $bystander->toString(), 'expectedRevision' => $revision, 'observedState' => 'stopped', 'position' => 0],
            'capability',
        );

        self::assertSame($runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
        self::assertSame(9.0, $session->getPosition());
    }

    private function store(ListeningSession $session, Uuid $endpoint): MockObject&PlaybackStoreInterface
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('endpoint')->willReturn([
            'id' => $endpoint->toString(), 'destination_id' => new Uuid()->toString(), 'epoch' => 1,
            'capabilities' => ['song', 'video'], 'available' => false, 'retired_at' => null,
        ]);
        $store->method('currentTransfer')->willReturn(null);
        $store->method('hasPendingControl')->willReturn(false);
        $store->method('snapshot')->willReturn([]);
        return $store;
    }

    private function coordinator(PlaybackStoreInterface $store): PlaybackCoordinator
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
