<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UnansweredControlExpiryTest extends TestCase
{
    private Uuid $owner;
    private Uuid $runner;
    private Uuid $destination;

    protected function setUp(): void
    {
        $this->owner = new Uuid();
        $this->runner = new Uuid();
        $this->destination = new Uuid();
    }

    #[DataProvider('confirmedIdleStates')]
    public function testAnUndisclosedPlayToAConfirmedIdleBrowserSettlesWithoutAStop(PlaybackState $confirmed): void
    {
        $session = $this->session($confirmed, 'play');
        $revision = $session->revision();
        $command = $this->expired('play', disclosed: false);
        $store = $this->store($session, $command, $confirmed->value);
        $store->expects(self::once())->method('settleNeverEffective')->with($command['attempt_id'], $revision + 1);
        $store->expects(self::never())->method('cancelCommands');
        $store->expects(self::never())->method('insertCommand');
        $store->expects(self::never())->method('markRunnerUnknown');
        $store->expects(self::once())->method('saveSession')->with($session);

        $this->snapshot($store);

        self::assertSame($confirmed, $session->desiredState());
        self::assertSame($confirmed->value, $session->observedState()->value);
        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame($this->destination, $session->selectedDestinationId());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame(12.5, $session->getPosition());
    }

    /** @return iterable<string,array{PlaybackState}> */
    public static function confirmedIdleStates(): iterable
    {
        yield 'stopped' => [PlaybackState::Stopped];
        yield 'paused' => [PlaybackState::Paused];
    }

    #[DataProvider('startingControls')]
    public function testADisclosedStartingControlTurnsTheSessionUnknownMarksTheRunnerAndIssuesAStop(string $type): void
    {
        $session = $this->session(PlaybackState::Stopped, 'play');
        $revision = $session->revision();
        $command = $this->expired($type, disclosed: true);
        $store = $this->store($session, $command, 'stopped');
        $store->expects(self::never())->method('settleNeverEffective');
        $store->expects(self::once())->method('cancelCommands')->with($command['attempt_id']);
        $store->expects(self::once())->method('markRunnerUnknown')->with($this->runner->toString());
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            fn(array $issued): bool => $issued['type'] === 'stop' && $issued['incarnation_id'] === $this->runner->toString()
                && $issued['attempt_id'] === $command['attempt_id'] && $issued['revision'] === $revision + 1,
        ));

        $this->snapshot($store);

        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('unknown', $session->observedState()->value);
        self::assertSame($this->runner, $session->activeIncarnationId());
    }

    /** @return iterable<string,array{string}> */
    public static function startingControls(): iterable
    {
        yield 'play' => ['play'];
        yield 'load' => ['load'];
        yield 'seek' => ['seek'];
    }

    #[DataProvider('silencingControls')]
    public function testAnUnansweredSilencingControlOnAConfirmedIdleRunnerSettlesEvenWhenItWasShown(string $type, bool $disclosed): void
    {
        $session = $this->session(PlaybackState::Paused, $type);
        $command = $this->expired($type, $disclosed);
        $store = $this->store($session, $command, 'paused');
        $store->expects(self::once())->method('settleNeverEffective')->with($command['attempt_id'], $session->revision() + 1);
        $store->expects(self::never())->method('insertCommand');
        $store->expects(self::never())->method('markRunnerUnknown');

        $this->snapshot($store);

        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame($this->runner, $session->activeIncarnationId());
    }

    /** @return iterable<string,array{string,bool}> */
    public static function silencingControls(): iterable
    {
        yield 'shown stop' => ['stop', true];
        yield 'shown pause' => ['pause', true];
        yield 'stop never shown' => ['stop', false];
    }

    /** @param array<string,mixed> $runner */
    #[DataProvider('runnersWithoutAnIdleProof')]
    public function testAnUndisclosedPlayKeepsTheUnknownPathWithoutAnIdleBrowserRunner(array $runner): void
    {
        $session = $this->session(PlaybackState::Stopped, 'play');
        $command = $this->expired('play', disclosed: false);
        $store = $this->store($session, $command, $runner['observed_state'], $runner['kind']);
        $store->expects(self::never())->method('settleNeverEffective');
        $store->expects(self::once())->method('cancelCommands')->with($command['attempt_id']);
        // The command never left the server, so the runner's own last report still stands.
        $store->expects(self::never())->method('markRunnerUnknown');
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            static fn(array $issued): bool => $issued['type'] === 'stop',
        ));

        $this->snapshot($store);

        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('unknown', $session->observedState()->value);
    }

    /** @return iterable<string,array{array{kind:string,observed_state:string}}> */
    public static function runnersWithoutAnIdleProof(): iterable
    {
        yield 'a receiver that is not a browser' => [['kind' => 'cast', 'observed_state' => 'stopped']];
        yield 'a browser that last reported playing' => [['kind' => 'browser', 'observed_state' => 'playing']];
        yield 'a browser whose last report is unknown' => [['kind' => 'browser', 'observed_state' => 'unknown']];
    }

    #[DataProvider('volumeAndQueueExpiries')]
    public function testAnExpiredVolumeOrQueueEditSettlesOnlyWhenItsBrowserRunnerWasNeverShownIt(string $type, bool $disclosed, string $kind, bool $settles): void
    {
        $session = $this->session(PlaybackState::Paused, 'pause');
        $revision = $session->revision();
        $command = $this->expired($type, $disclosed);
        $store = $this->store($session, $command, 'paused', $kind);
        $store->expects($settles ? self::once() : self::never())->method('settleNeverEffective')->with($command['attempt_id'], $revision + 1);
        $store->expects($settles ? self::never() : self::once())->method('cancelCommands')->with($command['attempt_id']);
        // Neither can start or stop playback: the session keeps its state and owes no stop either way.
        $store->expects(self::never())->method('insertCommand');
        $store->expects(self::never())->method('markRunnerUnknown');

        $this->snapshot($store);

        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame($revision + 1, $session->revision());
    }

    /** @return iterable<string,array{string,bool,string,bool}> */
    public static function volumeAndQueueExpiries(): iterable
    {
        foreach (['volume', 'queue'] as $type) {
            yield $type . ' never shown to a browser' => [$type, false, 'browser', true];
            yield $type . ' shown to a browser' => [$type, true, 'browser', false];
            yield $type . ' never shown to another receiver' => [$type, false, 'cast', false];
        }
    }

    public function testAnExpiredStopOnARunnerTheSessionHoldsUnknownIsIssuedAgain(): void
    {
        $session = $this->session(PlaybackState::Stopped, 'stop');
        $session->retainUncertainRunner();
        $session->control('stop', null, null, null, null, null);
        $command = $this->expired('stop', disclosed: false);
        $store = $this->store($session, $command, 'stopped');
        $store->expects(self::never())->method('settleNeverEffective');
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            static fn(array $issued): bool => $issued['type'] === 'stop',
        ));

        $this->snapshot($store);

        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('unknown', $session->observedState()->value);
    }

    #[DataProvider('startingControls')]
    public function testAStopSupersedingADisclosedStartingControlMarksTheRunnerUnknown(string $type): void
    {
        $session = $this->session(PlaybackState::Stopped, 'play');
        $revision = $session->revision();
        $pending = $this->pending($type, disclosed: true);
        $store = $this->store($session, null, 'stopped', pending: [$pending]);
        $store->expects(self::once())->method('cancelCommands')->with($pending['attempt_id']);
        $store->expects(self::never())->method('settleNeverEffective');
        $store->expects(self::once())->method('markRunnerUnknown')->with($this->runner->toString());
        $store->expects(self::never())->method('retireConfirmedStoppedBrowser');
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            fn(array $issued): bool => $issued['type'] === 'stop' && $issued['incarnation_id'] === $this->runner->toString(),
        ));

        $this->stop($store, $revision);

        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('unknown', $session->observedState()->value);
    }

    #[DataProvider('silencingTypes')]
    public function testAStopSupersedingADisclosedSilencingControlOnAConfirmedIdleRunnerSettlesIt(string $type): void
    {
        $session = $this->session(PlaybackState::Paused, 'pause');
        $revision = $session->revision();
        $pending = $this->pending($type, disclosed: true);
        $store = $this->store($session, null, 'paused', pending: [$pending]);
        // The settlement records the revision the session stood at, before the stop that replaces it.
        $store->expects(self::once())->method('settleNeverEffective')->with($pending['attempt_id'], $revision);
        $store->expects(self::never())->method('cancelCommands');
        $store->expects(self::never())->method('markRunnerUnknown');
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            static fn(array $issued): bool => $issued['type'] === 'stop' && $issued['revision'] === $revision + 1,
        ));

        $this->stop($store, $revision);

        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
    }

    /** @return iterable<string,array{string}> */
    public static function silencingTypes(): iterable
    {
        yield 'pause' => ['pause'];
        yield 'stop' => ['stop'];
    }

    /** @param array{type:string,reported:string,kind:string,confirmed:bool} $case */
    #[DataProvider('shownControlsThatStaySuperseded')]
    public function testAStopSupersedingAShownControlKeepsItSupersededWithoutAnIdleProofOrForAVolumeOrQueueEdit(array $case): void
    {
        $session = $this->session(PlaybackState::Paused, 'pause');
        if (!$case['confirmed']) {
            $session->retainUncertainRunner();
        }
        $revision = $session->revision();
        $pending = $this->pending($case['type'], disclosed: true);
        $store = $this->store($session, null, $case['reported'], $case['kind'], pending: [$pending]);
        $store->expects(self::once())->method('cancelCommands')->with($pending['attempt_id']);
        $store->expects(self::never())->method('settleNeverEffective');
        // None of these can have started playback, so what is known about the runner stands.
        $store->expects(self::never())->method('markRunnerUnknown');
        $store->expects(self::once())->method('insertCommand');

        $this->stop($store, $revision);

        self::assertSame($case['confirmed'] ? 'paused' : 'unknown', $session->observedState()->value);
    }

    /** @return iterable<string,array{array{type:string,reported:string,kind:string,confirmed:bool}}> */
    public static function shownControlsThatStaySuperseded(): iterable
    {
        yield 'a volume change' => [['type' => 'volume', 'reported' => 'paused', 'kind' => 'browser', 'confirmed' => true]];
        yield 'a queue edit' => [['type' => 'queue', 'reported' => 'paused', 'kind' => 'browser', 'confirmed' => true]];
        yield 'a pause on a runner that last reported playing' => [['type' => 'pause', 'reported' => 'playing', 'kind' => 'browser', 'confirmed' => true]];
        yield 'a stop on a runner the session holds unknown' => [['type' => 'stop', 'reported' => 'paused', 'kind' => 'browser', 'confirmed' => false]];
        yield 'a pause on a receiver that is not a browser' => [['type' => 'pause', 'reported' => 'paused', 'kind' => 'cast', 'confirmed' => true]];
    }

    #[DataProvider('everyControl')]
    public function testAStopSupersedingAControlItsRunnerWasNeverShownSettlesItAndKeepsWhatIsKnown(string $type, bool $confirmed): void
    {
        $session = $this->session(PlaybackState::Paused, 'pause');
        if (!$confirmed) {
            $session->retainUncertainRunner();
        }
        $revision = $session->revision();
        $pending = $this->pending($type, disclosed: false);
        $store = $this->store($session, null, $confirmed ? 'paused' : 'unknown', pending: [$pending]);
        $store->expects(self::once())->method('settleNeverEffective')->with($pending['attempt_id'], $revision);
        $store->expects(self::never())->method('cancelCommands');
        $store->expects(self::never())->method('markRunnerUnknown');
        $store->expects(self::once())->method('insertCommand');

        $this->stop($store, $revision);

        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame($confirmed ? 'paused' : 'unknown', $session->observedState()->value);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function everyControl(): iterable
    {
        foreach (['play', 'load', 'seek', 'pause', 'stop', 'volume', 'queue'] as $type) {
            yield $type . ' on a confirmed runner' => [$type, true];
        }
        yield 'play on a runner the session holds unknown' => ['play', false];
    }

    public function testAControlAReceiverThatIsNotABrowserWasNeverShownStaysSuperseded(): void
    {
        $session = $this->session(PlaybackState::Paused, 'pause');
        $revision = $session->revision();
        $pending = $this->pending('play', disclosed: false);
        $store = $this->store($session, null, 'paused', 'cast', pending: [$pending]);
        $store->expects(self::once())->method('cancelCommands')->with($pending['attempt_id']);
        $store->expects(self::never())->method('settleNeverEffective');

        $this->stop($store, $revision);

        self::assertSame('paused', $session->observedState()->value);
    }

    public function testAStopSupersedesEveryPendingControl(): void
    {
        $session = $this->session(PlaybackState::Stopped, 'play');
        $revision = $session->revision();
        $first = $this->pending('play', disclosed: false);
        $second = $this->pending('volume', disclosed: true);
        $store = $this->store($session, null, 'stopped', pending: [$first, $second]);
        $store->expects(self::once())->method('settleNeverEffective')->with($first['attempt_id'], $revision);
        $store->expects(self::once())->method('cancelCommands')->with($second['attempt_id']);
        $store->expects(self::once())->method('insertCommand');

        $this->stop($store, $revision);

        self::assertSame('stopped', $session->observedState()->value);
    }

    private function session(PlaybackState $confirmed, string $pending): ListeningSession
    {
        $session = ListeningSession::create($this->owner, [], 0, 12.5);
        $session->commitDestination($this->runner, $this->destination, $confirmed, 12.5);
        $session->control($pending, null, null, null, null, null);
        return $session;
    }

    /** @return array<string,mixed> */
    private function expired(string $type, bool $disclosed): array
    {
        return ['type' => $type, 'expires_at' => '2000-01-01T00:00:00+00:00', 'attempt_id' => new Uuid()->toString(),
            'request_id' => new Uuid()->toString(), 'incarnation_id' => $this->runner->toString(), 'request_digest' => 'digest',
            'disclosed_at' => $disclosed ? '2000-01-01T00:00:00+00:00' : null];
    }

    /** @return array<string,mixed> A control that has not expired. */
    private function pending(string $type, bool $disclosed): array
    {
        return ['expires_at' => '2999-01-01T00:00:00+00:00'] + $this->expired($type, $disclosed);
    }

    /**
     * @param array<string,mixed>|null $expired The pending control every read returns.
     * @param list<array<string,mixed>> $pending Pending controls that leave the list as they are closed.
     */
    private function store(ListeningSession $session, ?array $expired, string $reported, string $kind = 'browser', array $pending = []): MockObject&PlaybackStoreInterface
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturn(null);
        $close = static function (string $attempt) use (&$pending): void {
            $pending = array_values(array_filter($pending, static fn(array $command): bool => $command['attempt_id'] !== $attempt));
        };
        $store->method('pendingControl')->willReturnCallback(static function () use ($expired, &$pending): ?array {
            return $expired ?? $pending[0] ?? null;
        });
        $store->method('cancelCommands')->willReturnCallback($close);
        $store->method('settleNeverEffective')->willReturnCallback($close);
        $store->method('endpoint')->willReturn([
            'id' => $this->runner->toString(), 'destination_id' => $this->destination->toString(), 'epoch' => 1,
            'kind' => $kind, 'observed_state' => $reported, 'capabilities' => ['song', 'video'], 'available' => false, 'retired_at' => null,
        ]);
        $store->method('snapshot')->willReturn([]);
        return $store;
    }

    private function snapshot(PlaybackStoreInterface $store): void
    {
        $this->coordinator($store)->execute($this->owner, PlaybackOperation::Snapshot, []);
    }

    private function stop(PlaybackStoreInterface $store, int $revision): void
    {
        $this->coordinator($store)->execute($this->owner, PlaybackOperation::Control, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => $revision, 'action' => 'stop',
        ]);
    }

    private function coordinator(PlaybackStoreInterface $store): PlaybackCoordinator
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
