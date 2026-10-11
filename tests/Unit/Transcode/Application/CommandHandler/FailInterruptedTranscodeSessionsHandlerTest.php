<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\FailInterruptedTranscodeSessionsCommand;
use App\Transcode\Application\CommandHandler\FailInterruptedTranscodeSessionsHandler;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionState;
use PHPUnit\Framework\TestCase;

/**
 * A drain stops encoding loops; the sessions those loops served end as failed, so a client that
 * asks again after the restart gets a new session with a running loop instead of the old one.
 */
final class FailInterruptedTranscodeSessionsHandlerTest extends TestCase
{
    public function testEveryLiveSessionOfAnInterruptedJobFailsAndEndedSessionsKeepTheirState(): void
    {
        $jobId = Uuid::generate();
        $otherJobId = Uuid::generate();
        $pending = $this->session($jobId);
        $preparing = $this->session($jobId, SessionState::Preparing);
        $active = $this->session($jobId, SessionState::Preparing, SessionState::Active);
        $paused = $this->session($jobId, SessionState::Preparing, SessionState::Active, SessionState::Paused);
        $completed = $this->session($jobId, SessionState::Preparing, SessionState::Active, SessionState::Completed);
        $cancelled = $this->session($jobId, SessionState::Cancelled);
        $elsewhere = $this->session($otherJobId, SessionState::Preparing, SessionState::Active);

        $saved = [];
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::once())->method('findByJob')->with($jobId)
            ->willReturn([$pending, $preparing, $active, $paused, $completed, $cancelled]);
        $sessions->method('save')->willReturnCallback(static function (TranscodeSession $session) use (&$saved): void {
            $saved[] = $session;
        });

        $failed = (new FailInterruptedTranscodeSessionsHandler($sessions))(new FailInterruptedTranscodeSessionsCommand([$jobId]));

        self::assertSame(4, $failed);
        self::assertSame([$pending, $preparing, $active, $paused], $saved);
        foreach ($saved as $session) {
            self::assertSame(SessionState::Failed, $session->getSessionState());
        }
        self::assertSame(SessionState::Completed, $completed->getSessionState());
        self::assertSame(SessionState::Cancelled, $cancelled->getSessionState());
        self::assertSame(SessionState::Active, $elsewhere->getSessionState());
    }

    public function testNoJobsReadsNothing(): void
    {
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::never())->method('findByJob');
        $sessions->expects(self::never())->method('save');

        self::assertSame(0, (new FailInterruptedTranscodeSessionsHandler($sessions))(new FailInterruptedTranscodeSessionsCommand([])));
    }

    private function session(Uuid $jobId, SessionState ...$path): TranscodeSession
    {
        $session = TranscodeSession::create(Uuid::generate(), $jobId, Uuid::generate(), AudioProfile::streamingStereo());
        foreach ($path as $state) {
            match ($state) {
                SessionState::Preparing => $session->markPreparing(),
                SessionState::Active => $session->markActive(),
                SessionState::Paused => $session->markPaused(),
                SessionState::Completed => $session->markCompleted(),
                SessionState::Cancelled => $session->markCancelled(),
                default => self::fail('Unsupported fixture state ' . $state->value),
            };
        }

        return $session;
    }
}
