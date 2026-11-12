<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** An endpoint reports its own stop as it goes away, and the session is released in that request. */
final class PlaybackCloseReportTest extends TestCase
{
    private Uuid $owner;
    private Uuid $runner;

    protected function setUp(): void
    {
        $this->owner = new Uuid();
        $this->runner = new Uuid();
    }

    public function testTheReportRetiresTheIncarnationThenRunsTheProofsThenReleasesWhatStillNamesIt(): void
    {
        $session = $this->playingSession();
        $session->control('pause', null, null, null, null, null);
        $revision = $session->revision();
        $order = [];
        $store = $this->store($session, snapshot: null);
        $store->expects(self::once())->method('endpoint')->with($this->owner->toString(), $this->runner->toString(), 'capability')
            ->willReturnCallback(function () use (&$order): array {
                $order[] = 'authority';
                return ['id' => $this->runner->toString()];
            });
        $store->expects(self::once())->method('retireIncarnation')->with($this->runner->toString(), true, 61.0)->willReturnCallback(static function () use (&$order): void {
            $order[] = 'retire';
        });
        $store->expects(self::never())->method('retireConfirmedPausedBrowser');
        $store->expects(self::once())->method('supersedeIncarnationCommands')->with($this->runner->toString())->willReturnCallback(static function () use (&$order): void {
            $order[] = 'release';
        });
        $store->expects(self::once())->method('saveSession')->with($session);
        // Nothing is disclosed to an incarnation that has gone.
        $store->expects(self::once())->method('snapshot')->with($session, null)->willReturn(['revision' => $revision + 1]);
        foreach (['observe', 'reportCursor', 'insertCommand', 'completeLocalRecovery'] as $method) {
            $store->expects(self::never())->method($method);
        }

        $result = $this->close($store, ['revision' => $revision, 'media' => null, 'currentTrackIndex' => 0, 'position' => 61.0]);

        self::assertSame(['authority', 'retire', 'release'], $order);
        self::assertSame(['revision' => $revision + 1], $result);
        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame(61.0, $session->getPosition());
        self::assertSame($revision + 1, $session->revision());
    }

    public function testARunnerAProofReleasesKeepsTheStateItConfirmed(): void
    {
        $session = $this->playingSession();
        $session->control('stop', null, null, null, null, null);
        $session->confirmControl(false, null);
        $revision = $session->revision();
        $store = $this->store($session);
        $store->method('endpoint')->willReturn(['id' => $this->runner->toString()]);
        $store->expects(self::once())->method('retireIncarnation')->with($this->runner->toString(), true, 0.0);
        $store->expects(self::once())->method('retireConfirmedStoppedBrowser')->with($session)->willReturn(true);
        $store->expects(self::once())->method('saveSession')->with($session);

        $this->close($store, ['revision' => $revision, 'media' => null, 'currentTrackIndex' => 0, 'position' => 0.0]);

        self::assertNull($session->activeIncarnationId());
        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('stopped', $session->observedState()->value);
        self::assertSame(27.5, $session->getPosition());
        self::assertSame($revision + 1, $session->revision());
    }

    public function testAReportForAnIncarnationTheSessionDoesNotNameLeavesTheSessionAlone(): void
    {
        $session = $this->playingSession();
        $bystander = new Uuid();
        $store = $this->store($session);
        $store->method('endpoint')->willReturn(['id' => $bystander->toString()]);
        $store->expects(self::once())->method('retireIncarnation')->with($bystander->toString(), true, 5.0);
        foreach (['saveSession', 'saveTransfer', 'cancelCommands', 'insertCommand'] as $method) {
            $store->expects(self::never())->method($method);
        }

        $this->close($store, ['revision' => 0, 'media' => null, 'currentTrackIndex' => 0, 'position' => 5.0], $bystander);

        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame(0, $session->revision());
        self::assertSame('playing', $session->observedState()->value);
    }

    public function testTheCapabilityIsRequiredBeforeTheSessionIsLocked(): void
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->expects(self::never())->method('lockSession');
        $store->expects(self::never())->method('endpoint');

        foreach ([null, ''] as $capability) {
            try {
                $this->coordinator($store)->execute($this->owner, PlaybackOperation::Close, ['incarnationId' => $this->runner->toString(),
                    'checkpoint' => ['revision' => 0, 'media' => null, 'currentTrackIndex' => 0, 'position' => 0]], $capability);
                self::fail('A close report without a capability was accepted.');
            } catch (ConflictException $error) {
                self::assertSame('An endpoint capability is required.', $error->getMessage());
            }
        }
    }

    public function testAReportWithoutAUsableCheckpointRetiresNothing(): void
    {
        $session = $this->playingSession();
        $store = $this->store($session);
        $store->method('endpoint')->willReturn(['id' => $this->runner->toString()]);
        foreach (['retireIncarnation', 'supersedeIncarnationCommands', 'saveSession'] as $method) {
            $store->expects(self::never())->method($method);
        }

        foreach ([null, 'cursor', ['revision' => 0, 'currentTrackIndex' => 0, 'position' => 1.0], ['revision' => '0', 'media' => null, 'currentTrackIndex' => 0, 'position' => 1.0],
            ['revision' => 0, 'media' => null, 'currentTrackIndex' => 0, 'position' => -1]] as $checkpoint) {
            try {
                $this->close($store, $checkpoint);
                self::fail('A close report without a usable checkpoint was accepted.');
            } catch (InvalidInputException) {
                self::assertSame($this->runner, $session->activeIncarnationId());
            }
        }
    }

    private function playingSession(): ListeningSession
    {
        $session = ListeningSession::create($this->owner, [], 0, 27.5);
        $session->commitDestination($this->runner, new Uuid(), PlaybackState::Playing, 27.5);
        return $session;
    }

    /** @param array<string,mixed>|null $snapshot What every snapshot answers, unless the test expects one of its own. */
    private function store(ListeningSession $session, ?array $snapshot = []): MockObject&PlaybackStoreInterface
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturn(null);
        $store->method('pendingControl')->willReturn(null);
        if ($snapshot !== null) {
            $store->method('snapshot')->willReturn($snapshot);
        }
        return $store;
    }

    /** @return array<string,mixed> */
    private function close(PlaybackStoreInterface $store, mixed $checkpoint, ?Uuid $reporter = null): array
    {
        return $this->coordinator($store)->execute($this->owner, PlaybackOperation::Close, ['incarnationId' => ($reporter ?? $this->runner)->toString(), 'checkpoint' => $checkpoint], 'capability');
    }

    private function coordinator(PlaybackStoreInterface $store): PlaybackCoordinator
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
