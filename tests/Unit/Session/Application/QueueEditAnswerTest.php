<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A queue edit cannot start, stop or move playback, so its answer says nothing about playback. */
final class QueueEditAnswerTest extends TestCase
{
    /** @return iterable<string,array{PlaybackState,ObservedPlaybackState,string,?float,float}> */
    public static function answers(): iterable
    {
        yield 'applied on a confirmed pause' => [PlaybackState::Paused, ObservedPlaybackState::Paused, 'applied', 18.5, 18.5];
        yield 'rejected on a confirmed pause' => [PlaybackState::Paused, ObservedPlaybackState::Paused, 'rejected', null, 12.0];
        yield 'applied while the runner is unknown' => [PlaybackState::Playing, ObservedPlaybackState::Unknown, 'applied', 18.5, 18.5];
        yield 'rejected while the runner plays' => [PlaybackState::Playing, ObservedPlaybackState::Playing, 'rejected', 18.5, 18.5];
        yield 'applied on a confirmed stop' => [PlaybackState::Stopped, ObservedPlaybackState::Stopped, 'applied', null, 12.0];
    }

    #[DataProvider('answers')]
    public function testTheAnswerRecordsTheCursorAndLeavesWhatIsKnownAboutPlaybackAlone(
        PlaybackState $desired,
        ObservedPlaybackState $observed,
        string $outcome,
        ?float $reported,
        float $cursor,
    ): void {
        $owner = new Uuid();
        $runner = new Uuid();
        $attempt = new Uuid();
        $commandId = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 12.0);
        $session->commitDestination($runner, new Uuid(), $desired, 12.0);
        $session->observePlayback($observed, 12.0);
        $session->control('queue', null, [], 0, null, null);
        $revision = $session->revision();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('endpoint')->willReturn(['id' => $runner->toString(), 'epoch' => 1, 'observed_state' => 'playing']);
        $store->method('command')->willReturn(['id' => $commandId->toString(), 'type' => 'queue', 'incarnation_id' => $runner->toString(),
            'attempt_id' => $attempt->toString(), 'epoch' => 1, 'revision' => $revision, 'acknowledged_at' => null,
            'request_id' => new Uuid()->toString(), 'request_digest' => 'digest']);
        $store->expects(self::once())->method('acknowledgeCommand')->with($commandId->toString(), $outcome);
        // The runner's own last report stands: only its cursor and its presence are written.
        $store->expects(self::once())->method('reportCursor')->with($runner->toString(), $cursor);
        $store->expects(self::never())->method('observe');
        $store->expects(self::never())->method('insertCommand');
        $store->method('snapshot')->willReturn([]);
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class))->execute(
            $owner,
            PlaybackOperation::Acknowledge,
            ['incarnationId' => $runner->toString(), 'commandId' => $commandId->toString(), 'attemptId' => $attempt->toString(),
                'epoch' => 1, 'revision' => $revision, 'outcome' => $outcome] + ($reported === null ? [] : ['position' => $reported]),
            'execution-capability',
        );

        self::assertSame($desired, $session->desiredState());
        self::assertSame($observed, $session->observedState());
        self::assertSame($cursor, $session->getPosition());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame($runner, $session->activeIncarnationId());
    }
}
