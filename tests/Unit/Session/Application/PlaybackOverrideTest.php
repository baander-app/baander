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
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The listener's own decision ends the hold of a destination that is no longer registered. */
final class PlaybackOverrideTest extends TestCase
{
    private Uuid $owner;
    private Uuid $runner;
    private Uuid $asker;
    private string $requestId;

    protected function setUp(): void
    {
        $this->owner = new Uuid();
        $this->runner = new Uuid();
        $this->asker = new Uuid();
        $this->requestId = new Uuid()->toString();
    }

    public function testAnAcceptedOverrideReleasesRetiresWithoutCallingTheRunnerStoppedAndRecordsOneSettledRow(): void
    {
        $session = $this->unknownSession();
        $revision = $session->revision();
        $order = [];
        $store = $this->store($session, null, [$this->runner->toString() => ['available' => false]]);
        $store->expects(self::once())->method('supersedeIncarnationCommands')->with($this->runner->toString())->willReturnCallback(static function () use (&$order): void {
            $order[] = 'release';
        });
        $store->expects(self::once())->method('retireIncarnation')->with($this->runner->toString(), false)->willReturnCallback(static function () use (&$order): void {
            $order[] = 'retire';
        });
        $store->expects(self::once())->method('insertCommand')->with(self::callback(function (array $command) use ($revision, &$order): bool {
            $order[] = 'row';
            return $command['id'] === $this->requestId && $command['request_id'] === $this->requestId && $command['attempt_id'] === $this->requestId
                && $command['type'] === 'stop' && $command['incarnation_id'] === $this->runner->toString() && $command['epoch'] === 3
                && $command['revision'] === $revision + 1;
        }));
        $store->expects(self::once())->method('acknowledgeCommand')->with($this->requestId, 'overridden')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'settle';
        });
        $store->expects(self::once())->method('saveSession')->with($session);
        $store->method('requestedCommand')->willReturn(['id' => $this->requestId, 'request_id' => $this->requestId, 'outcome' => 'overridden', 'rejection_reason' => null]);

        $result = $this->override($store, $revision);

        self::assertSame(['release', 'retire', 'row', 'settle'], $order);
        self::assertSame(['requestId' => $this->requestId, 'commandId' => $this->requestId, 'outcome' => 'overridden', 'rejectionReason' => null], $result['requestResult']);
        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame(27.5, $session->getPosition());
        self::assertSame($revision + 1, $session->revision());
    }

    public function testARepeatedRequestReturnsItsRecordedResultAndTouchesNothing(): void
    {
        $session = $this->unknownSession();
        $revision = $session->revision();
        $store = $this->store($session, null, [$this->runner->toString() => ['available' => false]]);
        $store->expects(self::once())->method('rememberRequest')->with($session->getId()->toString(), $this->requestId, self::isString())->willReturn(true);
        foreach (['retireConfirmedPausedBrowser', 'retireConfirmedStoppedBrowser', 'supersedeIncarnationCommands', 'retireIncarnation', 'insertCommand', 'acknowledgeCommand', 'saveSession'] as $method) {
            $store->expects(self::never())->method($method);
        }
        $store->method('requestedCommand')->willReturn(['id' => $this->requestId, 'request_id' => $this->requestId, 'outcome' => 'overridden', 'rejection_reason' => null]);

        // The replay is answered before the revision is looked at: the session has moved since.
        $result = $this->override($store, $revision - 1);

        self::assertSame('overridden', $result['requestResult']['outcome']);
        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
    }

    public function testTheRequestIsTheSameRequestWhicheverIncarnationOfTheTabSendsIt(): void
    {
        $digests = [];
        foreach ([$this->asker, new Uuid()] as $incarnation) {
            $session = $this->unknownSession();
            $store = $this->store($session, null, [$this->runner->toString() => ['available' => false]]);
            $store->expects(self::once())->method('rememberRequest')->willReturnCallback(static function (string $sessionId, string $requestId, string $digest) use (&$digests): bool {
                $digests[] = $digest;
                return true;
            });
            $store->method('requestedCommand')->willReturn(null);
            $this->coordinator($store)->execute($this->owner, PlaybackOperation::Override, ['requestId' => $this->requestId, 'expectedRevision' => count($digests),
                'incarnationId' => $incarnation->toString()], 'capability');
        }

        self::assertCount(2, $digests);
        self::assertSame($digests[0], $digests[1]);
    }

    public function testARunnerAProofReleasesInTheSameRequestIsTheOneTheRowNames(): void
    {
        $session = ListeningSession::create($this->owner, [], 0, 27.5);
        $session->commitDestination($this->runner, new Uuid(), PlaybackState::Paused, 27.5);
        $revision = $session->revision();
        $store = $this->store($session, null, [$this->runner->toString() => ['available' => false]]);
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->with($session)->willReturn(true);
        // The proof already retired the runner and closed nothing: nothing is superseded or retired again.
        $store->expects(self::never())->method('supersedeIncarnationCommands');
        $store->expects(self::never())->method('retireIncarnation');
        $store->expects(self::once())->method('insertCommand')->with(self::callback(
            fn(array $command): bool => $command['type'] === 'stop' && $command['incarnation_id'] === $this->runner->toString() && $command['revision'] === $revision + 1,
        ));
        $store->expects(self::once())->method('acknowledgeCommand')->with($this->requestId, 'overridden');

        $this->override($store, $revision);

        self::assertNull($session->activeIncarnationId());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame($revision + 1, $session->revision());
    }

    public function testAnUncertainTransferIsFailedForItsTargetAndOnlyARegisteredSourceKeepsTheSession(): void
    {
        foreach ([true, false] as $sourceRegistered) {
            $session = $this->unknownSession();
            $revision = $session->revision();
            $target = new Uuid();
            $transfer = ['id' => new Uuid()->toString(), 'state' => 'uncertain', 'source_incarnation_id' => $this->runner->toString(),
                'target_incarnation_id' => $target->toString(), 'expires_at' => new DateTimeImmutable('+20 seconds')->format(DATE_ATOM)];
            $store = $this->store($session, $transfer, [$target->toString() => ['available' => false], $this->runner->toString() => ['available' => $sourceRegistered]]);
            $store->method('sourceQuiesced')->willReturn(true);
            $retired = [];
            $store->method('retireIncarnation')->willReturnCallback(static function (string $incarnationId, bool $stopped) use (&$retired): void {
                $retired[] = [$incarnationId, $stopped];
            });
            $store->expects(self::once())->method('saveTransfer')->with(self::callback(static fn(array $saved): bool => $saved['state'] === 'failed'));
            $store->expects(self::once())->method('insertCommand')->with(self::callback(
                static fn(array $command): bool => $command['type'] === 'stop' && $command['incarnation_id'] === $target->toString(),
            ));

            $this->override($store, $revision);

            self::assertSame($sourceRegistered ? $this->runner : null, $session->activeIncarnationId());
            self::assertSame(PlaybackState::Paused, $session->desiredState());
            self::assertSame('paused', $session->observedState()->value);
            self::assertSame($sourceRegistered ? [[$target->toString(), false]] : [[$target->toString(), false], [$this->runner->toString(), false]], $retired);
        }
    }

    /** @return iterable<string,array{string,?string,array<string,mixed>}> The refusal, the state of an open transfer and the row of the destination. */
    public static function refusals(): iterable
    {
        yield 'the runner is still registered' => ['The destination is still connected. Stop playback there.', null, ['available' => true]];
        yield 'the target of an uncertain transfer is still registered' => ['The destination is still connected. Stop playback there.', 'uncertain', ['available' => true]];
        yield 'a transfer is preparing' => ['Playback controls wait for transfer reconciliation.', 'preparing', ['available' => false]];
        yield 'a transfer is quiescing' => ['Playback controls wait for transfer reconciliation.', 'quiescing', ['available' => false]];
        yield 'a transfer is starting' => ['Playback controls wait for transfer reconciliation.', 'starting', ['available' => false]];
        yield 'the destination is of another kind' => ['No playback is waiting to be overridden.', null, ['available' => false, 'kind' => 'cast']];
    }

    /** @param array<string,mixed> $destination */
    #[DataProvider('refusals')]
    public function testARefusedOverrideChangesNothing(string $refusal, ?string $transferState, array $destination): void
    {
        $session = $this->unknownSession();
        $revision = $session->revision();
        $target = new Uuid();
        $transfer = $transferState === null ? null : ['id' => new Uuid()->toString(), 'state' => $transferState, 'source_incarnation_id' => $this->runner->toString(),
            'target_incarnation_id' => $target->toString(), 'expires_at' => new DateTimeImmutable('+20 seconds')->format(DATE_ATOM)];
        $store = $this->store($session, $transfer, [($transfer === null ? $this->runner : $target)->toString() => $destination]);
        $this->expectNoChange($store);

        $this->assertRefused($refusal, $store, $revision);

        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
        self::assertSame('unknown', $session->observedState()->value);
    }

    public function testAnOverrideIsRefusedOnAnotherRevisionForATabThatIsNotRegisteredAndWhereNothingIsHeld(): void
    {
        $session = $this->unknownSession();
        $store = $this->store($session, null, [$this->runner->toString() => ['available' => false]]);
        $this->expectNoChange($store);
        $this->assertRefused('Playback revision conflict.', $store, $session->revision() + 1);

        $store = $this->store($session, null, [$this->runner->toString() => ['available' => false]], askerAvailable: false);
        $this->expectNoChange($store);
        $this->assertRefused('Connect this browser before playing here.', $store, $session->revision());

        $nobody = ListeningSession::create($this->owner, [], 0, 0);
        $store = $this->store($nobody, null, []);
        $this->expectNoChange($store);
        $this->assertRefused('No playback is waiting to be overridden.', $store, 0);
    }

    public function testTheCapabilityIsRequiredBeforeTheSessionIsLocked(): void
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->expects(self::never())->method('lockSession');

        try {
            $this->coordinator($store)->execute($this->owner, PlaybackOperation::Override, ['requestId' => $this->requestId, 'expectedRevision' => 0, 'incarnationId' => $this->asker->toString()]);
            self::fail('An override without a capability was accepted.');
        } catch (ConflictException $error) {
            self::assertSame('An endpoint capability is required.', $error->getMessage());
        }
    }

    /** A runner that was shown a start, did not answer and is owed a stop. */
    private function unknownSession(): ListeningSession
    {
        $session = ListeningSession::create($this->owner, [], 0, 27.5);
        $session->commitDestination($this->runner, new Uuid(), PlaybackState::Playing, 27.5);
        $session->retainUncertainRunner();
        $session->advanceRevision();
        return $session;
    }

    /**
     * @param array<string,mixed>|null $transfer
     * @param array<string,array<string,mixed>> $destinations What is known of each incarnation the override may look at.
     */
    private function store(ListeningSession $session, ?array $transfer, array $destinations, bool $askerAvailable = true): MockObject&PlaybackStoreInterface
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturnCallback(static function () use (&$transfer): ?array {
            return $transfer;
        });
        $store->method('saveTransfer')->willReturnCallback(static function (array $saved) use (&$transfer): void {
            // A failed transfer is no longer the current one.
            $transfer = in_array($saved['state'], ['failed', 'committed'], true) ? null : $saved;
        });
        $store->method('pendingControl')->willReturn(null);
        $store->method('endpoint')->willReturnCallback(fn(string $owner, string $incarnationId, ?string $capability = null): array => $incarnationId === $this->asker->toString()
            ? ['id' => $incarnationId, 'kind' => 'browser', 'available' => $askerAvailable, 'epoch' => 9]
            : ($destinations[$incarnationId] ?? []) + ['id' => $incarnationId, 'kind' => 'browser', 'available' => true, 'epoch' => 3,
                'destination_id' => new Uuid()->toString(), 'retired_at' => null, 'observed_state' => 'unknown']);
        $store->method('snapshot')->willReturn([]);
        return $store;
    }

    private function expectNoChange(MockObject&PlaybackStoreInterface $store): void
    {
        foreach (['supersedeIncarnationCommands', 'retireIncarnation', 'cancelCommands', 'insertCommand', 'acknowledgeCommand', 'saveSession'] as $method) {
            $store->expects(self::never())->method($method);
        }
    }

    /** @return array<string,mixed> */
    private function override(PlaybackStoreInterface $store, int $revision): array
    {
        return $this->coordinator($store)->execute($this->owner, PlaybackOperation::Override, ['requestId' => $this->requestId, 'expectedRevision' => $revision,
            'incarnationId' => $this->asker->toString()], 'capability');
    }

    private function assertRefused(string $refusal, PlaybackStoreInterface $store, int $revision): void
    {
        try {
            $this->override($store, $revision);
            self::fail('The override was accepted.');
        } catch (ConflictException $error) {
            self::assertSame($refusal, $error->getMessage());
        }
    }

    private function coordinator(PlaybackStoreInterface $store): PlaybackCoordinator
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
