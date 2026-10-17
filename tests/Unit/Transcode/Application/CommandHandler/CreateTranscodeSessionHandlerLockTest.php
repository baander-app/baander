<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreateTranscodeSessionHandlerLockTest extends TestCase
{
    private TranscodeJobPortInterface&MockObject $jobs;
    private TranscodeSessionPortInterface&MockObject $sessions;
    private TranscodeExecutionOwnershipPort&MockObject $ownership;
    private TranscodeJob $job;
    private TranscodeSession $session;
    private CreateTranscodeSessionCommand $command;
    private CreateTranscodeSessionHandler $handler;
    private bool $insideTransaction = false;
    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $video = Uuid::generate();
        $user = Uuid::generate();
        $profile = AudioProfile::streamingStereo();
        $tier = QualityTier::p720();
        $this->job = TranscodeJob::create($video, $tier, '/fixture/video', $user);
        $this->session = TranscodeSession::create($user, $this->job->getId(), $video, $profile);
        $this->command = new CreateTranscodeSessionCommand($user, $video, $tier, $profile);
        $this->jobs = $this->createMock(TranscodeJobPortInterface::class);
        $this->sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $this->ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn('/fixture/video');
        $this->jobs->expects(self::once())->method('getOrCreateJob')->willReturn($this->job);
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->insideTransaction = true;
            try {
                return $operation();
            } finally {
                $this->insideTransaction = false;
            }
        });
        $sleeper = $this->createStub(SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(function (float $seconds): void {
            self::assertFalse($this->insideTransaction, 'Waiting cannot hold the producer transaction.');
            $this->sleeps[] = $seconds;
        });
        $this->handler = new CreateTranscodeSessionHandler($this->jobs, $this->sessions, $storage, $this->ownership, $transaction, $sleeper);
    }

    private function snapshot(string $outcome): TranscodeRuntimeSnapshot
    {
        return new TranscodeRuntimeSnapshot($this->job->getId(), $this->session->getId(), $this->command->requestId, new DesiredTranscodeState($this->command->getAudioProfile()), 1, 0, 1, $outcome, null, null, in_array($outcome, ['accepted', 'ready'], true));
    }

    public function testDurableIntentIsWrittenInsideTransactionAndStartupWaitsForAcceptance(): void
    {
        $this->ownership->expects(self::once())->method('lockAdmission')->willReturnCallback(function (): void { self::assertTrue($this->insideTransaction); });
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->jobs->expects(self::once())->method('save');
        $this->ownership->expects(self::once())->method('admit')->willReturnCallback(function (): TranscodeRuntimeSnapshot {
            self::assertTrue($this->insideTransaction);
            return $this->snapshot('pending');
        });
        $this->ownership->method('find')->willReturnOnConsecutiveCalls(null, $this->snapshot('pending'), $this->snapshot('accepted'));
        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertSame([0.1], $this->sleeps);
    }

    public function testExistingAcceptedSessionIsReusedWithoutAnotherAdmission(): void
    {
        $this->ownership->method('find')->willReturn($this->snapshot('accepted'));
        $this->sessions->expects(self::once())->method('findByUuid')->with($this->session->getId())->willReturn($this->session);
        $this->sessions->expects(self::never())->method('createSession');
        $this->jobs->expects(self::never())->method('save');
        $this->ownership->expects(self::never())->method('admit');
        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertSame([], $this->sleeps);
    }

    public function testExpiredAcceptanceDoesNotReportOwnedExecution(): void
    {
        $accepted = $this->snapshot('accepted');
        $expired = new TranscodeRuntimeSnapshot($accepted->jobId, $accepted->sessionId, $accepted->requestId, $accepted->desired, $accepted->desiredRevision, $accepted->observedRevision, $accepted->generation, 'accepted', null, null, false);
        $this->ownership->method('find')->willReturn($expired);
        $this->sessions->expects(self::once())->method('findByUuid')->willReturn($this->session);
        $this->sessions->expects(self::never())->method('createSession');
        $this->ownership->expects(self::once())->method('expireAdmission')->willReturn(false);
        $this->expectException(TranscodeStartupUnavailableException::class);
        ($this->handler)($this->command);
    }

    public function testReplayOfARefusedAdmissionCannotCreateAnotherStartup(): void
    {
        $this->ownership->method('find')->willReturn($this->snapshot('refused'));
        $this->sessions->expects(self::once())->method('findByUuid')->willReturn($this->session);
        $this->sessions->expects(self::never())->method('createSession');
        $this->ownership->expects(self::never())->method('admit');
        $this->expectException(TranscodeStartupUnavailableException::class);
        ($this->handler)($this->command);
    }

    public function testUnacceptedStartupExpiresTheDurableRequestAfterTheBound(): void
    {
        $this->ownership->method('find')->willReturnOnConsecutiveCalls(null, ...array_fill(0, 50, $this->snapshot('pending')));
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->ownership->method('admit')->willReturn($this->snapshot('pending'));
        $this->ownership->expects(self::once())->method('expireAdmission')->with($this->job->getId(), $this->session->getId(), $this->command->requestId)->willReturn(true);
        $this->expectException(TranscodeStartupUnavailableException::class);
        try {
            ($this->handler)($this->command);
        } finally {
            self::assertSame(array_fill(0, 50, 0.1), $this->sleeps);
        }
    }

    public function testAcceptanceRacingTimeoutIsReturnedWithoutRefusingItsOwner(): void
    {
        $this->ownership->method('find')->willReturnOnConsecutiveCalls(null, ...[...array_fill(0, 50, $this->snapshot('pending')), $this->snapshot('accepted')]);
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->ownership->method('admit')->willReturn($this->snapshot('pending'));
        $this->ownership->expects(self::once())->method('expireAdmission')->willReturn(false);
        self::assertSame($this->session, ($this->handler)($this->command));
    }
}
