<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\Service;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeControlResult;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Service\StaleTranscodeSessionExpiry;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Model\TranscodeSessionState;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class StaleTranscodeSessionExpiryTest extends TestCase
{
    private const string NOW = '2026-11-10 12:00:00+00';

    private TranscodeJob $job;
    /** @var list<TranscodeSession> */
    private array $listed = [];
    /** @var array<string, TranscodeSession> */
    private array $current = [];
    private ?TranscodeRuntimeSnapshot $runtime = null;
    /** @var list<array{session: string, status: ?string, seek: bool}> */
    private array $controls = [];
    /** @var list<string> */
    private array $locks = [];
    private int $savedSessions = 0;
    private int $savedJobs = 0;
    private StaleTranscodeSessionExpiry $expiry;
    /** @var list<array{string, array<string, mixed>}> */
    private array $errors = [];
    /** @var list<string> Sessions whose save fails */
    private array $failing = [];

    protected function setUp(): void
    {
        $this->job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/fixture/video/job', Uuid::generate(), requestId: Uuid::generate()->toString());
        $this->job->attachSession();
        $this->job->markInProgress();
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findActive')->willReturnCallback(fn (): array => $this->listed);
        $sessions->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?TranscodeSession => $this->current[$id->toString()] ?? null);
        $sessions->method('save')->willReturnCallback(function (TranscodeSession $session): void {
            if (in_array($session->getId()->toString(), $this->failing, true)) {
                throw new \RuntimeException('The session could not be saved.');
            }
            ++$this->savedSessions;
        });
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturnCallback(fn (): TranscodeJob => $this->job);
        $jobs->method('save')->willReturnCallback(function (): void { ++$this->savedJobs; });
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('lockAdmission')->willReturnCallback(function (): void { $this->locks[] = 'admission'; });
        $ownership->method('lockSession')->willReturnCallback(function (): void { $this->locks[] = 'session'; });
        $ownership->method('find')->willReturnCallback(fn (): ?TranscodeRuntimeSnapshot => $this->runtime);
        $ownership->method('control')->willReturnCallback(function (Uuid $jobId, Uuid $sessionId, string $requestId, ?string $status = null, ?AudioProfile $audioProfile = null, ?float $position = null, ?int $segmentIndex = null, bool $seek = true): TranscodeControlResult {
            self::assertSame(['admission', 'session'], $this->locks, 'The execution is cancelled under the admission and session locks.');
            self::assertSame($requestId, Uuid::fromString($requestId)->toString());
            self::assertNull($position);
            $this->controls[] = ['session' => $sessionId->toString(), 'status' => $status, 'seek' => $seek];
            return new TranscodeControlResult($this->runtime ?? throw new \LogicException('No runtime.'), true);
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            $this->errors[] = [(string) $message, $context];
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $this->expiry = new StaleTranscodeSessionExpiry($sessions, $ownership, $jobs, $transaction, new MockClock(new DateTimeImmutable(self::NOW)), $logger);
    }

    public function testAbandonedSessionCancelsItsHeldExecutionAndReleasesItsJob(): void
    {
        // The client left a day ago; the worker still holds the execution at the
        // lead throttle with a live lease.
        $session = $this->session(SessionState::Active, '-24 hours -1 second');
        $this->runtime = $this->runtimeFor($session, 'ready', live: true);

        self::assertSame(1, $this->expiry->expire());

        self::assertSame([['session' => $session->getId()->toString(), 'status' => 'cancelled', 'seek' => false]], $this->controls);
        self::assertSame(SessionState::Cancelled, $session->getSessionState());
        // Released, not cancelled: an in-progress job without a session is what cleanup removes.
        self::assertSame(0, $this->job->getReferenceCount());
        self::assertSame('in_progress', $this->job->getStatus()->value);
        self::assertSame([1, 1], [$this->savedSessions, $this->savedJobs]);
    }

    /** @return iterable<string, array{SessionState, string}> */
    public static function sessionsInsideTheBound(): iterable
    {
        yield 'a paused player that renewed its stream URL an hour ago' => [SessionState::Paused, '-1 hour'];
        yield 'a paused player just inside the bound' => [SessionState::Paused, '-23 hours -59 minutes'];
        yield 'a playing session that just reported' => [SessionState::Active, '-5 seconds'];
    }

    #[DataProvider('sessionsInsideTheBound')]
    public function testSessionInsideTheBoundKeepsItsExecutionAndJob(SessionState $state, string $touched): void
    {
        $session = $this->session($state, $touched);
        $this->runtime = $this->runtimeFor($session, 'ready', live: true);

        self::assertSame(0, $this->expiry->expire());

        self::assertSame([], $this->controls);
        self::assertSame($state, $session->getSessionState());
        self::assertSame(1, $this->job->getReferenceCount());
        self::assertSame([0, 0], [$this->savedSessions, $this->savedJobs]);
    }

    public function testClientThatSignsBeforeTheLockKeepsItsSession(): void
    {
        $listed = $this->session(SessionState::Paused, '-25 hours');
        // The same session, read again under the lock after its client signed.
        $this->current[$listed->getId()->toString()] = $this->reconstitute($listed, SessionState::Paused, '-1 second');
        $this->runtime = $this->runtimeFor($listed, 'ready', live: true);

        self::assertSame(0, $this->expiry->expire());

        self::assertSame(['admission', 'session'], $this->locks);
        self::assertSame([], $this->controls);
        self::assertSame(1, $this->job->getReferenceCount());
    }

    public function testFinishedPresentationKeepsItsSessionForResume(): void
    {
        $session = $this->session(SessionState::Active, '-3 days');
        $this->runtime = $this->runtimeFor($session, 'complete', live: false);

        self::assertSame(0, $this->expiry->expire());

        self::assertSame([], $this->controls);
        self::assertSame(SessionState::Active, $session->getSessionState());
        self::assertSame(1, $this->job->getReferenceCount());
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function executionsNoWorkerActsOn(): iterable
    {
        yield 'a refused execution' => ['refused', true];
        yield 'a failed execution' => ['failed', true];
        yield 'an execution another session took over' => ['ready', false];
        yield 'no execution at all' => [null, true];
    }

    #[DataProvider('executionsNoWorkerActsOn')]
    public function testAbandonedSessionWithoutARunnableExecutionOfItsOwnOnlyReleasesItsJob(?string $outcome, bool $owned): void
    {
        $session = $this->session(SessionState::Active, '-2 days');
        $other = TranscodeSession::create($session->getUserId(), $this->job->getId(), $session->getVideoId(), AudioProfile::streamingStereo());
        $this->runtime = $outcome === null ? null : $this->runtimeFor($owned ? $session : $other, $outcome, live: false);

        self::assertSame(1, $this->expiry->expire());

        self::assertSame([], $this->controls, 'Only an execution this session owns and a worker still acts on is cancelled.');
        self::assertSame(SessionState::Cancelled, $session->getSessionState());
        self::assertSame(0, $this->job->getReferenceCount());
    }

    public function testSessionThatCannotExpireDoesNotKeepTheOthersFromExpiring(): void
    {
        $broken = $this->session(SessionState::Active, '-3 days');
        $abandoned = $this->session(SessionState::Active, '-2 days');
        $this->failing[] = $broken->getId()->toString();

        self::assertSame(1, $this->expiry->expire());

        self::assertSame(SessionState::Cancelled, $abandoned->getSessionState());
        self::assertCount(1, $this->errors);
        self::assertSame($broken->getId()->toString(), $this->errors[0][1]['session']);
    }

    /** @return iterable<string, array{?SessionState}> */
    public static function sessionsGoneUnderTheLock(): iterable
    {
        yield 'a session another request already cancelled' => [SessionState::Cancelled];
        yield 'a session that completed' => [SessionState::Completed];
        yield 'a session that no longer exists' => [null];
    }

    #[DataProvider('sessionsGoneUnderTheLock')]
    public function testSessionThatEndedBeforeTheLockIsLeftAlone(?SessionState $state): void
    {
        $listed = $this->session(SessionState::Active, '-2 days');
        if ($state === null) {
            unset($this->current[$listed->getId()->toString()]);
        } else {
            $this->current[$listed->getId()->toString()] = $this->reconstitute($listed, $state, '-2 days');
        }
        $this->runtime = $this->runtimeFor($listed, 'ready', live: true);

        self::assertSame(0, $this->expiry->expire());

        self::assertSame([], $this->controls);
        self::assertSame(1, $this->job->getReferenceCount());
        self::assertSame([0, 0], [$this->savedSessions, $this->savedJobs]);
    }

    private function session(SessionState $state, string $touched): TranscodeSession
    {
        $template = TranscodeSession::create(Uuid::generate(), $this->job->getId(), $this->job->getVideoId(), AudioProfile::streamingStereo());
        $session = $this->reconstitute($template, $state, $touched);
        $this->listed[] = $session;
        $this->current[$session->getId()->toString()] = $session;

        return $session;
    }

    private function reconstitute(TranscodeSession $source, SessionState $state, string $touched): TranscodeSession
    {
        $at = new DateTimeImmutable(self::NOW)->modify($touched);

        return TranscodeSession::reconstitute(new TranscodeSessionState(
            id: $source->getId(),
            publicId: new PublicId(),
            userId: $source->getUserId(),
            jobId: $source->getJobId(),
            videoId: $source->getVideoId(),
            state: $state,
            priority: SessionPriority::Normal,
            audioProfile: AudioProfile::streamingStereo(),
            currentSegmentIndex: 0,
            wallClockOffset: 118.86,
            metrics: [],
            createdAt: $at,
            updatedAt: $at,
        ));
    }

    private function runtimeFor(TranscodeSession $session, string $outcome, bool $live): TranscodeRuntimeSnapshot
    {
        return new TranscodeRuntimeSnapshot($this->job->getId(), $session->getId(), $this->job->getRequestId(),
            new DesiredTranscodeState(AudioProfile::streamingStereo(), position: 118.86), 5, 5, 5, $outcome, '/fixture/video/job/generation-5', null, $live);
    }
}
