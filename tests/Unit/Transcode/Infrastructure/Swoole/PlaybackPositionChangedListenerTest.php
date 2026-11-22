<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Party\Application\Command\PausePlaybackCommand;
use App\Party\Application\Command\StartPlaybackCommand;
use App\Party\Application\CommandHandler\PausePlaybackHandler;
use App\Party\Application\CommandHandler\StartPlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\CommandHandler\UpdateTranscodePositionHandler;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeControlResult;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Swoole\PlaybackPositionChangedListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class PlaybackPositionChangedListenerTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function actions(): iterable
    {
        yield ['seek'];
        yield ['pause'];
        yield ['resume'];
    }

    #[DataProvider('actions')]
    public function testPartyControlsEnterDurableSessionCommand(string $action): void
    {
        $jobId = Uuid::generate();
        $sessionId = Uuid::generate();
        $runtime = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $runtime->method('find')->willReturn(new TranscodeRuntimeSnapshot($jobId, $sessionId, 'admission',
            new DesiredTranscodeState(AudioProfile::streamingStereo()), 1, 1, 1, 'ready', '/cache/generation-1', null, true));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static fn(object $command): bool =>
            $command instanceof UpdateTranscodePositionCommand && $command->sessionId->equals($sessionId)
            && $command->position === 30.5 && $command->action === $action))->willReturnCallback(static fn(object $command): Envelope => new Envelope($command));
        (new PlaybackPositionChangedListener($runtime, $bus))(new PlaybackPositionChanged($jobId, 30.5, $action));
    }

    /**
     * The party's own start and pause handlers steer the encoder through this listener and the
     * session use case; only the stored runtime state is kept in memory.
     */
    public function testAPartyPlayAfterAPartyPauseResumesTheEncoder(): void
    {
        $host = Uuid::generate();
        $video = Uuid::generate();
        $job = TranscodeJob::create($video, QualityTier::p720(), '/media/party', $host, requestId: 'party-client');
        $job->attachSession();
        $session = TranscodeSession::create($host, $job->getId(), $video, AudioProfile::streamingStereo());
        $session->markPreparing();
        $session->markActive();
        $runtime = new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), 'party-client',
            new DesiredTranscodeState(AudioProfile::streamingStereo()), 1, 1, 1, 'ready', '/cache/generation-1', null, true);

        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $sessions->method('findActiveByUserAndVideo')->willReturn([$session]);
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturn($job);
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(static function () use (&$runtime): TranscodeRuntimeSnapshot {
            return $runtime;
        });
        $ownership->method('control')->willReturnCallback(
            static function (Uuid $jobId, Uuid $sessionId, string $requestId, ?string $status = null, ?AudioProfile $audioProfile = null, ?float $position = null) use (&$runtime): TranscodeControlResult {
                $previous = $runtime;
                $runtime = new TranscodeRuntimeSnapshot(
                    $previous->jobId,
                    $previous->sessionId,
                    $previous->requestId,
                    new DesiredTranscodeState(
                        $previous->desired->audioProfile,
                        $status ?? $previous->desired->status,
                        $position ?? $previous->desired->position,
                    ),
                    $previous->desiredRevision + 1,
                    $previous->observedRevision,
                    $previous->generation,
                    $previous->outcome,
                    $previous->outputDirectory,
                    $previous->error,
                    $previous->ownershipLive,
                );

                return new TranscodeControlResult($runtime, true);
            },
        );
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $positions = new UpdateTranscodePositionHandler(new TranscodePlaybackControl($sessions, $ownership, $transaction, $jobs));
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (UpdateTranscodePositionCommand $command): Envelope => new Envelope($command, [new HandledStamp($positions($command), 'position')]),
        );
        $listener = new PlaybackPositionChangedListener($ownership, $bus);

        $party = SyncedPartySession::create($host, $video, $job->getId());
        $parties = $this->createStub(PartySessionPortInterface::class);
        $parties->method('findByUuid')->willReturn($party);
        $parties->method('startPlayback')->willReturnCallback(static fn (Uuid $id, ?float $position = null) => $party->startPlayback($position));
        $parties->method('pausePlayback')->willReturnCallback(static fn () => $party->pausePlayback());
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use ($listener): object {
            if ($event instanceof PlaybackPositionChanged) {
                $listener($event);
            }

            return $event;
        });

        (new StartPlaybackHandler($parties, $events))(new StartPlaybackCommand($party->getId(), $host, 12.0));
        (new PausePlaybackHandler($parties, $events))(new PausePlaybackCommand($party->getId(), $host));
        self::assertSame('paused', $runtime->desired->status, 'The party pause reached the encoder.');

        (new StartPlaybackHandler($parties, $events))(new StartPlaybackCommand($party->getId(), $host));

        self::assertSame('running', $runtime->desired->status);
    }

    public function testPartyWithoutExecutionIntentDoesNotStartAnEncoder(): void
    {
        $runtime = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $runtime->method('find')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new PlaybackPositionChangedListener($runtime, $bus))(new PlaybackPositionChanged(Uuid::generate(), 0.0, 'seek'));
    }
}
