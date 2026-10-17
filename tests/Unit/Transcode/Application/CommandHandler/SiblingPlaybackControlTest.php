<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CancelTranscodeSessionCommand;
use App\Transcode\Application\Command\PauseTranscodeSessionCommand;
use App\Transcode\Application\Command\ResumeTranscodeSessionCommand;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\Command\UpdateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CancelTranscodeSessionHandler;
use App\Transcode\Application\CommandHandler\PauseTranscodeSessionHandler;
use App\Transcode\Application\CommandHandler\ResumeTranscodeSessionHandler;
use App\Transcode\Application\CommandHandler\UpdateTranscodePositionHandler;
use App\Transcode\Application\CommandHandler\UpdateTranscodeSessionHandler;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Model\TranscodeSessionState;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiblingPlaybackControlTest extends TestCase
{
    /** @var list<TranscodeSession> */
    private array $sessions;
    /** @var array<string, TranscodeJob> */
    private array $jobs = [];
    /** @var array<string, TranscodeRuntimeSnapshot> */
    private array $runtimes = [];
    /** @var list<array{session: string, request: string, seek: bool}> */
    private array $controls = [];
    /** @var list<string> */
    private array $locks = [];
    /** @var array<string, bool> */
    private array $receipts = [];
    private int $persistenceWrites = 0;
    private TranscodeSessionPortInterface $sessionPort;
    private TranscodeJobPortInterface $jobPort;
    private TranscodeExecutionOwnershipPort $ownership;
    private TransactionPortInterface $transaction;

    protected function setUp(): void
    {
        $owner = Uuid::generate();
        $video = Uuid::generate();
        $this->sessions = [];
        foreach ([QualityTier::p360(), QualityTier::p720(), QualityTier::p1080(), QualityTier::p360(), QualityTier::p360()] as $index => $tier) {
            $userId = $index === 3 ? Uuid::generate() : $owner;
            $videoId = $index === 4 ? Uuid::generate() : $video;
            $job = TranscodeJob::create($videoId, $tier, '/media/' . $index, $userId);
            $job->attachSession();
            $session = TranscodeSession::create($userId, $job->getId(), $videoId, AudioProfile::streamingStereo());
            $session->markPreparing();
            $session->markActive();
            $this->sessions[] = $session;
            $this->jobs[$job->getId()->toString()] = $job;
            $this->runtimes[$job->getId()->toString()] = new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), 'admitted', new DesiredTranscodeState(AudioProfile::streamingStereo(), position: 60.0), 5, 5, 3, 'ready', '/generation-3', null, true);
        }
        // A historical attachment to a sibling job is not its current runtime session.
        $old = TranscodeSession::create($owner, $this->sessions[1]->getJobId(), $video, AudioProfile::streamingStereo());
        $this->sessions[] = $old;
        $this->sessionPort = $this->createStub(TranscodeSessionPortInterface::class);
        $this->sessionPort->method('findByUuid')->willReturnCallback(function (Uuid $id): ?TranscodeSession {
            foreach ($this->sessions as $session) {
                if ($session->getId()->equals($id)) {
                    return $session;
                }
            }
            return null;
        });
        $this->sessionPort->method('findActiveByUserAndVideo')->willReturnCallback(function (Uuid $userId, Uuid $videoId): array {
            self::assertTrue($userId->equals($this->sessions[0]->getUserId()));
            self::assertTrue($videoId->equals($this->sessions[0]->getVideoId()));
            return array_values(array_filter(
                $this->sessions, static fn (TranscodeSession $session): bool => !in_array($session->getSessionState(), [SessionState::Completed, SessionState::Failed, SessionState::Cancelled], true),
            ));
        });
        $this->sessionPort->method('save')->willReturnCallback(function (): void { ++$this->persistenceWrites; });
        $this->jobPort = $this->createStub(TranscodeJobPortInterface::class);
        $this->jobPort->method('save')->willReturnCallback(function (): void { ++$this->persistenceWrites; });
        $this->jobPort->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?TranscodeJob => $this->jobs[$id->toString()] ?? null);
        $this->ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $this->ownership->method('lockAdmission')->willReturnCallback(function (Uuid $id): void { $this->locks[] = 'video:' . $id; });
        $this->ownership->method('lockSession')->willReturnCallback(function (Uuid $id): void { $this->locks[] = $id->toString(); });
        $this->ownership->method('find')->willReturnCallback(fn (Uuid $id): ?TranscodeRuntimeSnapshot => $this->runtimes[$id->toString()] ?? null);
        $this->ownership->method('control')->willReturnCallback(function (Uuid $job, Uuid $session, string $request, ?string $status = null, ?AudioProfile $profile = null, ?float $position = null, ?int $segment = null, bool $seek = true): TranscodeRuntimeSnapshot {
            $this->controls[] = ['session' => $session->toString(), 'request' => $request, 'seek' => $seek];
            $previous = $this->runtimes[$job->toString()];
            if (isset($this->receipts[$session . ':' . $request]) || in_array($previous->outcome, ['failed', 'refused', 'cancelled'], true)) {
                return $previous;
            }
            $this->receipts[$session . ':' . $request] = true;
            $desired = $previous->desired;
            return $this->runtimes[$job->toString()] = new TranscodeRuntimeSnapshot($job, $session, 'admitted', new DesiredTranscodeState($profile ?? $desired->audioProfile, $desired->status === 'cancelled' ? 'cancelled' : ($status ?? $desired->status), $position ?? $desired->position), 6, 5, 3, $status === 'cancelled' ? 'cancelled' : $previous->outcome, '/generation-3', null, true);
        });
        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(static fn (callable $operation) => $operation());
    }

    #[DataProvider('actions')]
    public function testControlsReachOnlyTheThreeCurrentOwnedTiers(string $action): void
    {
        if ($action === 'resume') {
            foreach (array_slice($this->sessions, 0, 3) as $session) {
                $session->markPaused();
            }
        }
        $request = Uuid::generate()->toString();
        $this->dispatch($action, $request);
        self::assertCount(3, $this->controls);
        self::assertSame([$request], array_values(array_unique(array_column($this->controls, 'request'))));
        foreach (array_slice($this->sessions, 0, 3) as $session) {
            $runtime = $this->runtimes[$session->getJobId()->toString()];
            self::assertSame(match ($action) { 'pause' => 'paused', 'cancel' => 'cancelled', default => 'running' }, $runtime->desired->status);
            self::assertSame(in_array($action, ['seek', 'progress'], true) ? 61.0 : 60.0, $session->getWallClockOffset());
            self::assertSame($action === 'profile' ? 'mobile_mono' : 'streaming_stereo', $session->getAudioProfile()->name);
        }
        foreach (array_slice($this->sessions, 3) as $session) {
            self::assertSame(0.0, $session->getWallClockOffset());
        }
        $sorted = array_map(static fn (TranscodeSession $session): string => $session->getId()->toString(), array_slice($this->sessions, 0, 3));
        sort($sorted);
        self::assertSame(['video:' . $this->sessions[0]->getVideoId(), ...$sorted], $this->locks);
    }

    #[DataProvider('seekGenerations')]
    public function testSeekGenerationIsCheckedBeforeAnyTierChanges(int $generation, ?int $expectedGeneration, bool $accepted): void
    {
        $source = $this->sessions[0];
        $this->runtimes[$source->getJobId()->toString()] = new TranscodeRuntimeSnapshot(
            $source->getJobId(), $source->getId(), 'admitted',
            new DesiredTranscodeState(AudioProfile::streamingStereo(), position: 60.0),
            6, 5, $generation, 'ready', '/generation-' . $generation, null, true,
        );
        $before = $this->runtimes;
        $this->dispatch('seek', Uuid::generate()->toString(), expectedRevision: 5, expectedGeneration: $expectedGeneration);

        if (!$accepted) {
            self::assertSame([], $this->controls);
            self::assertSame([], $this->receipts);
            self::assertSame(0, $this->persistenceWrites);
            self::assertSame($before, $this->runtimes);
            foreach ($this->sessions as $session) {
                self::assertSame(0.0, $session->getWallClockOffset());
            }
            return;
        }
        self::assertCount(3, $this->controls);
        foreach (array_slice($this->sessions, 0, 3) as $session) {
            self::assertSame(61.0, $session->getWallClockOffset());
        }
    }

    /** @return iterable<string, array{int, ?int, bool}> */
    public static function seekGenerations(): iterable
    {
        yield 'stale native seek' => [4, 3, false];
        yield 'current generation despite newer heartbeat revision' => [3, 3, true];
        yield 'explicit intent without generation' => [4, null, true];
    }

    /** @return iterable<string, array{string}> */
    public static function actions(): iterable
    {
        foreach (['pause', 'resume', 'profile', 'seek', 'progress', 'cancel'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('staleProgressStates')]
    public function testStaleProgressLeavesEverySiblingUntouched(int $generation, int $revision, string $status): void
    {
        $source = $this->sessions[0];
        $this->runtimes[$source->getJobId()->toString()] = new TranscodeRuntimeSnapshot(
            $source->getJobId(), $source->getId(), 'admitted',
            new DesiredTranscodeState(AudioProfile::streamingStereo(), $status, 60.0),
            $revision, $revision, $generation, 'ready', '/generation-' . $generation, null, true,
        );

        $this->dispatch('progress', Uuid::generate()->toString());

        self::assertSame([], $this->controls);
        foreach ($this->sessions as $session) {
            self::assertSame(0.0, $session->getWallClockOffset());
        }
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function staleProgressStates(): iterable
    {
        yield 'recovered generation' => [4, 5, 'running'];
        yield 'newer control' => [3, 6, 'running'];
        yield 'paused source' => [3, 5, 'paused'];
    }

    /** @return iterable<string, array{string}> */
    public static function terminalEncodingOutcomes(): iterable
    {
        yield 'failed' => ['failed'];
        yield 'refused' => ['refused'];
    }

    #[DataProvider('terminalEncodingOutcomes')]
    public function testTerminalSourceDoesNotMutateCurrentSiblingIntent(string $outcome): void
    {
        $source = $this->sessions[0];
        $this->runtimes[$source->getJobId()->toString()] = new TranscodeRuntimeSnapshot(
            $source->getJobId(), $source->getId(), 'admitted', new DesiredTranscodeState(AudioProfile::streamingStereo()),
            5, 5, 3, $outcome, null, 'encoder failed',
        );

        $this->dispatch('pause', Uuid::generate()->toString());

        self::assertCount(1, $this->controls);
        foreach (array_slice($this->sessions, 0, 3) as $session) {
            self::assertSame(SessionState::Active, $session->getSessionState());
            self::assertSame('running', $this->runtimes[$session->getJobId()->toString()]->desired->status);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function completedPlaybackControls(): iterable
    {
        yield 'pause completed source' => ['pause'];
        yield 'progress completed source' => ['progress'];
        yield 'cancel completed source' => ['cancel'];
    }

    #[DataProvider('completedPlaybackControls')]
    public function testCompletedEncodingKeepsPlaybackControlsAndReferencesLive(string $action): void
    {
        $source = $this->sessions[0];
        $job = $this->jobs[$source->getJobId()->toString()];
        $job->markInProgress();
        $job->markCompleted();
        $this->runtimes[$source->getJobId()->toString()] = new TranscodeRuntimeSnapshot(
            $source->getJobId(), $source->getId(), 'admitted',
            new DesiredTranscodeState(AudioProfile::streamingStereo(), position: 60.0),
            5, 5, 3, 'complete', '/generation-3', null,
        );
        $request = Uuid::generate()->toString();

        $this->dispatch($action, $request);

        self::assertCount(3, $this->controls);
        foreach (array_slice($this->sessions, 0, 3) as $session) {
            self::assertSame(match ($action) { 'pause' => SessionState::Paused, 'cancel' => SessionState::Cancelled, default => SessionState::Active }, $session->getSessionState());
            self::assertSame($action === 'progress' ? 61.0 : 60.0, $session->getWallClockOffset());
            self::assertSame($action === 'cancel' ? 0 : 1, $this->jobs[$session->getJobId()->toString()]->getReferenceCount());
        }
        if ($action === 'cancel') {
            $this->dispatch($action, $request);
            self::assertSame(0, $job->getReferenceCount());
        } else {
            self::assertSame('complete', $this->runtimes[$source->getJobId()->toString()]->outcome);
            self::assertSame(3, $this->runtimes[$source->getJobId()->toString()]->generation);
        }
    }

    public function testCancellationDetachesEachTierOnceAcrossRetries(): void
    {
        $request = Uuid::generate()->toString();
        $this->dispatch('cancel', $request);
        $this->dispatch('cancel', $request);
        foreach (array_slice($this->sessions, 0, 3) as $session) {
            self::assertSame(SessionState::Cancelled, $session->getSessionState());
            self::assertSame(0, $this->jobs[$session->getJobId()->toString()]->getReferenceCount());
        }
        self::assertSame(1, $this->jobs[$this->sessions[3]->getJobId()->toString()]->getReferenceCount());
    }

    public function testAReplayedControlUsesCanonicalIntentForANewSibling(): void
    {
        $third = $this->sessions[2];
        unset($this->sessions[2]);
        $pause = Uuid::generate()->toString();
        $this->dispatch('pause', $pause);
        $this->dispatch('resume', Uuid::generate()->toString());
        $this->sessions[] = $third;

        $this->dispatch('pause', $pause);

        self::assertSame('running', $this->runtimes[$third->getJobId()->toString()]->desired->status);
        self::assertSame(SessionState::Active, $third->getSessionState());
        self::assertSame('running', $this->runtimes[$this->sessions[0]->getJobId()->toString()]->desired->status);
    }

    public function testAnOldForeignAttachmentCannotControlTheJobsOwner(): void
    {
        $source = $this->sessions[0];
        $this->sessions[0] = TranscodeSession::reconstitute(new TranscodeSessionState(...[
            ...get_object_vars($source->getState()),
            'userId' => Uuid::generate(),
        ]));

        $this->dispatch('pause', Uuid::generate()->toString());

        self::assertSame([], $this->controls);
    }

    public function testCancellationUsesRuntimeTransitionDespiteAStaleSessionProjection(): void
    {
        $source = $this->sessions[0];
        $job = $this->jobs[$source->getJobId()->toString()];
        $job->attachSession();
        $request = Uuid::generate()->toString();
        $this->dispatch('cancel', $request);
        self::assertSame(1, $job->getReferenceCount());
        $source->getState()->state = SessionState::Active;

        $this->dispatch('cancel', $request);

        self::assertSame(1, $job->getReferenceCount());
        self::assertSame(SessionState::Cancelled, $source->getSessionState());
    }

    private function dispatch(string $action, string $request, int $expectedRevision = 5, ?int $expectedGeneration = 3): void
    {
        $source = $this->sessions[0]->getId();
        $playback = new TranscodePlaybackControl($this->sessionPort, $this->ownership, $this->transaction, $this->jobPort);
        match ($action) {
            'pause' => (new PauseTranscodeSessionHandler($playback))(new PauseTranscodeSessionCommand($source, $request)),
            'resume' => (new ResumeTranscodeSessionHandler($playback))(new ResumeTranscodeSessionCommand($source, $request)),
            'profile' => (new UpdateTranscodeSessionHandler($playback))(new UpdateTranscodeSessionCommand($source, AudioProfile::mobileMono(), $request)),
            'cancel' => (new CancelTranscodeSessionHandler($playback))(new CancelTranscodeSessionCommand($source, $request)),
            default => (new UpdateTranscodePositionHandler($playback))(new UpdateTranscodePositionCommand($source, 61.0, $action, $request, $expectedGeneration, $expectedRevision)),
        };
    }
}
