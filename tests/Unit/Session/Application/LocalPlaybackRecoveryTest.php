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
use PHPUnit\Framework\TestCase;

final class LocalPlaybackRecoveryTest extends TestCase
{
    /** @return iterable<string,array{string,?string,bool,bool,bool,bool}> */
    public static function roles(): iterable
    {
        yield 'active' => ['active', null, false, true, true, false];
        yield 'source preparing' => ['source', 'preparing', false, true, true, false];
        yield 'source quiescing' => ['source', 'quiescing', false, true, true, false];
        yield 'target preparing' => ['target', 'preparing', false, false, true, false];
        yield 'target quiescing unproven' => ['target', 'quiescing', false, false, true, true];
        yield 'target starting proven' => ['target', 'starting', true, false, true, false];
        yield 'target uncertain unproven' => ['target', 'uncertain', false, false, true, true];
        yield 'target uncertain proven' => ['target', 'uncertain', true, false, true, false];
        yield 'source starting' => ['source', 'starting', true, false, false, false];
        yield 'source uncertain' => ['source', 'uncertain', true, false, false, false];
        yield 'unrelated' => ['unrelated', 'starting', true, false, false, false];
    }

    #[DataProvider('roles')]
    public function testRecoveryPreservesEveryOtherUnprovenRunner(
        string $role,
        ?string $phase,
        bool $sourceProven,
        bool $release,
        bool $advance,
        bool $stopRequired,
    ): void {
        $owner = new Uuid();
        $source = new Uuid();
        $target = new Uuid();
        $destination = new Uuid();
        $incarnation = match ($role) {
            'active', 'source' => $source,
            'target' => $target,
            default => new Uuid(),
        };
        $session = ListeningSession::create($owner, [], 0, 4);
        $session->commitDestination($source, $destination, PlaybackState::Playing, 4);
        $transfer = $phase === null ? null : [
            'id' => new Uuid()->toString(), 'source_incarnation_id' => $source->toString(),
            'target_incarnation_id' => $target->toString(), 'state' => $phase,
        ];
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->expects(self::once())->method('lockSession')->with($owner->toString())->willReturn($session);
        $store->method('localRecovery')->willReturn(['recovery_result' => null]);
        $store->method('currentTransfer')->willReturn($transfer);
        $store->method('sourceQuiesced')->willReturn($sourceProven);
        $store->method('endpoint')->willReturn(['destination_id' => $destination->toString(),
            'retired_at' => null, 'observed_state' => 'paused', 'epoch' => 1]);
        $store->expects(self::once())->method('supersedeIncarnationCommands')->with($incarnation->toString());
        $store->expects($advance && $phase !== null ? self::once() : self::never())->method('cancelCommands');
        $store->expects($advance ? self::once() : self::never())->method('saveSession')->with($session);
        $store->expects($advance && $phase !== null ? self::once() : self::never())->method('saveTransfer')
            ->with(self::callback(static fn(array $saved): bool => $saved['state'] === 'failed'));
        $store->expects($stopRequired ? self::once() : self::never())->method('insertCommand')
            ->with(self::callback(static fn(array $command): bool => $command['type'] === 'stop'
                && $command['incarnation_id'] === $source->toString() && $command['revision'] === 1));
        $store->expects(self::once())->method('completeLocalRecovery')->with(
            $incarnation->toString(),
            ['incarnationId' => $incarnation->toString(), 'outcome' => 'recovered', 'revision' => $advance ? 1 : 0],
        );
        $store->method('snapshot')->willReturn([]);
        $service = $this->coordinator($store);

        $result = $service->execute($owner, PlaybackOperation::RecoverLocal, [
            'incarnationId' => $incarnation->toString(), 'tabId' => new Uuid()->toString(),
            'recoveryCredential' => str_repeat('a', 64),
            'checkpoint' => ['revision' => 0, 'media' => null, 'currentTrackIndex' => 0, 'position' => 8],
        ]);

        self::assertSame($release ? null : $source, $session->activeIncarnationId());
        self::assertSame($advance ? 1 : 0, $session->revision());
        self::assertSame($release ? 8.0 : 4.0, $session->getPosition());
        self::assertArrayNotHasKey('endpoint', $result);
        self::assertSame($stopRequired ? 'unknown' : (($advance && !($role === 'target' && $phase === 'preparing')) ? 'paused' : 'playing'), $session->observedState()->value);
    }

    /** @return iterable<string,array{string,bool}> The transfer's state, and whether its source ever quiesced. */
    public static function transfersWhoseSourceIsGone(): iterable
    {
        yield 'quiescing' => ['quiescing', false];
        yield 'starting' => ['starting', true];
        yield 'uncertain' => ['uncertain', true];
    }

    #[DataProvider('transfersWhoseSourceIsGone')]
    public function testARecoveredTargetDoesNotHandTheSessionBackToASourceRecordedGoneAndStopped(string $phase, bool $quiesced): void
    {
        $owner = new Uuid();
        $source = new Uuid();
        $target = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 4);
        $session->commitDestination($source, new Uuid(), PlaybackState::Playing, 4);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('localRecovery')->willReturn(['recovery_result' => null]);
        $store->method('currentTransfer')->willReturn(['id' => new Uuid()->toString(), 'source_incarnation_id' => $source->toString(),
            'target_incarnation_id' => $target->toString(), 'state' => $phase]);
        $store->method('sourceQuiesced')->willReturn($quiesced);
        $store->method('endpoint')->willReturn(['destination_id' => new Uuid()->toString(), 'retired_at' => '2026-11-11 12:00:00.000000+00:00',
            'observed_state' => 'stopped', 'recovery_result' => null, 'epoch' => 1]);
        $store->expects(self::once())->method('saveTransfer')->with(self::callback(static fn(array $saved): bool => $saved['state'] === 'failed'));
        $store->expects(self::once())->method('saveSession')->with($session);
        // The source is silent by its own record, so no stop is owed to it.
        $store->expects(self::never())->method('insertCommand');
        $store->method('snapshot')->willReturn([]);

        $this->coordinator($store)->execute($owner, PlaybackOperation::RecoverLocal, [
            'incarnationId' => $target->toString(), 'tabId' => new Uuid()->toString(), 'recoveryCredential' => str_repeat('a', 64),
            'checkpoint' => ['revision' => 5, 'media' => null, 'currentTrackIndex' => 0, 'position' => 8],
        ]);

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame(4.0, $session->getPosition());
        self::assertSame(1, $session->revision());
    }

    public function testConsumedCredentialSkipsExpirationAndAllMutationEvenForANewAttempt(): void
    {
        $owner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $stored = ['incarnationId' => new Uuid()->toString(), 'outcome' => 'recovered', 'revision' => 7];
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('localRecovery')->willReturn(['recovery_result' => $stored]);
        foreach (['currentTransfer', 'pendingControl', 'saveSession', 'saveTransfer', 'supersedeIncarnationCommands', 'completeLocalRecovery'] as $method) {
            $store->expects(self::never())->method($method);
        }
        $store->expects(self::once())->method('snapshot')->with($session, null)->willReturn(['revision' => 22]);

        $result = $this->coordinator($store)->execute($owner, PlaybackOperation::RecoverLocal, [
            'incarnationId' => $stored['incarnationId'], 'tabId' => new Uuid()->toString(), 'recoveryCredential' => str_repeat('a', 64),
        ]);

        self::assertSame(22, $result['revision']);
        self::assertSame($stored, $result['recoveryResult']);
    }

    public function testExpiredRecoveryStopRemainsPendingWithPausedIntent(): void
    {
        $owner = new Uuid();
        $source = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $session->control('pause', null, null, null, null, null);
        $session->commitDestination($source, new Uuid(), PlaybackState::Paused, 0);
        // A recovery leaves a source it could not prove stopped unknown, with this stop owed.
        $session->retainUncertainRunner();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturn(null);
        $store->method('pendingControl')->willReturn(['type' => 'stop', 'expires_at' => '2000-01-01T00:00:00+00:00',
            'attempt_id' => new Uuid()->toString(), 'request_id' => new Uuid()->toString(),
            'incarnation_id' => $source->toString(), 'request_digest' => 'digest', 'disclosed_at' => null]);
        $store->method('endpoint')->willReturn(['epoch' => 1]);
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            static fn(array $command): bool
            => $command['type'] === 'stop' && $command['incarnation_id'] === $source->toString()
            && $command['payload']['desiredState'] === 'paused' && $command['revision'] === 2,
        ));
        $store->method('snapshot')->willReturn([]);

        $this->coordinator($store)->execute($owner, PlaybackOperation::Snapshot, []);

        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('unknown', $session->observedState()->value);
    }

    public function testRejectedRecoveryStopCreatesANewStopObligation(): void
    {
        $owner = new Uuid();
        $source = new Uuid();
        $attempt = new Uuid();
        $commandId = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $session->control('pause', null, null, null, null, null);
        $session->commitDestination($source, new Uuid(), PlaybackState::Paused, 0);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('endpoint')->willReturn(['id' => $source->toString(), 'epoch' => 1]);
        $store->method('command')->willReturn(['id' => $commandId->toString(), 'type' => 'stop', 'incarnation_id' => $source->toString(),
            'attempt_id' => $attempt->toString(), 'epoch' => 1, 'revision' => 1, 'acknowledged_at' => null,
            'request_id' => new Uuid()->toString(), 'request_digest' => 'digest']);
        $store->expects(self::once())->method('acknowledgeCommand')->with($commandId->toString(), 'rejected');
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            static fn(array $command): bool
            => $command['type'] === 'stop' && $command['revision'] === 2 && $command['payload']['desiredState'] === 'paused',
        ));
        $store->method('snapshot')->willReturn([]);

        $this->coordinator($store)->execute($owner, PlaybackOperation::Acknowledge, [
            'incarnationId' => $source->toString(), 'commandId' => $commandId->toString(), 'attemptId' => $attempt->toString(),
            'epoch' => 1, 'revision' => 1, 'outcome' => 'rejected',
        ], 'execution-capability');

        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('unknown', $session->observedState()->value);
    }

    private function coordinator(PlaybackStoreInterface $store): PlaybackCoordinator
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
