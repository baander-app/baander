<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Domain\ValueObject\AudioProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscodeControlRevisionTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    public function testSeekKeepsPauseAndReplayCannotReverseCommittedControls(): void
    {
        $initial = $this->admit();
        $paused = $this->runtime->control($this->jobId, $this->sessionId, 'pause', status: 'paused');
        $seek = $this->runtime->control($this->jobId, $this->sessionId, 'seek', position: 45.5);
        self::assertSame('paused', $seek->desired->status);
        self::assertSame(45.5, $seek->desired->position);
        self::assertSame($initial->generation + 1, $seek->generation);
        self::assertSame($paused->desiredRevision + 1, $seek->desiredRevision);
        $replayed = $this->runtime->control($this->jobId, $this->sessionId, 'pause', status: 'paused');
        self::assertSame($seek->desiredRevision, $replayed->desiredRevision);
        self::assertSame(45.5, $replayed->desired->position);
    }

    public function testOlderPauseReplayCannotUndoALaterResume(): void
    {
        $this->admit();
        $this->runtime->control($this->jobId, $this->sessionId, 'pause-a', status: 'paused');
        $resumed = $this->runtime->control($this->jobId, $this->sessionId, 'resume-b', status: 'running');
        $replayed = $this->runtimeFor($this->second)->control($this->jobId, $this->sessionId, 'pause-a', status: 'paused');
        self::assertSame('running', $replayed->desired->status);
        self::assertSame($resumed->desiredRevision, $replayed->desiredRevision);
        self::assertSame($resumed->generation, $replayed->generation);
    }

    public function testOlderSeekReplayCannotReplaceALaterSeek(): void
    {
        $this->admit();
        $this->runtime->control($this->jobId, $this->sessionId, 'seek-a', position: 10);
        $latest = $this->runtime->control($this->jobId, $this->sessionId, 'seek-b', position: 60);
        $replayed = $this->runtimeFor($this->second)->control($this->jobId, $this->sessionId, 'seek-a', position: 10);
        self::assertSame(60.0, $replayed->desired->position);
        self::assertSame($latest->desiredRevision, $replayed->desiredRevision);
        self::assertSame($latest->generation, $replayed->generation);
    }

    public function testInitiallyUnchangedControlIsStillRememberedAfterLaterChanges(): void
    {
        $this->admit();
        $this->runtime->control($this->jobId, $this->sessionId, 'resume-a', status: 'running');
        $paused = $this->runtime->control($this->jobId, $this->sessionId, 'pause-b', status: 'paused');
        $replayed = $this->runtimeFor($this->second)->control($this->jobId, $this->sessionId, 'resume-a', status: 'running');
        self::assertSame('paused', $replayed->desired->status);
        self::assertSame($paused->desiredRevision, $replayed->desiredRevision);
    }

    public function testRolledBackControlDoesNotLeaveARequestReceipt(): void
    {
        $initial = $this->admit();
        $this->first->beginTransaction();
        $this->runtime->control($this->jobId, $this->sessionId, 'pause-rolled-back', status: 'paused');
        $this->first->rollBack();
        $retry = $this->runtimeFor($this->second)->control($this->jobId, $this->sessionId, 'pause-rolled-back', status: 'paused');
        self::assertSame('paused', $retry->desired->status);
        self::assertSame($initial->desiredRevision + 1, $retry->desiredRevision);
    }

    public function testCancelledPendingExecutionCannotBeClaimedOrResumed(): void
    {
        $this->admit();
        $cancelled = $this->runtime->control($this->jobId, $this->sessionId, 'cancel', status: 'cancelled');
        self::assertNull($this->runtime->claim($this->jobId, $this->worker()));
        $resume = $this->runtime->control($this->jobId, $this->sessionId, 'resume', status: 'running');
        self::assertSame('cancelled', $resume->desired->status);
        self::assertSame($cancelled->desiredRevision, $resume->desiredRevision);
    }

    public function testControlTurnoverFencesAggregateSavesAndRefreshesTheLiveOwner(): void
    {
        $this->admit();
        $old = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($old);
        self::assertTrue($this->runtime->observe($old, 'accepted'));
        $this->runtime->control($this->jobId, $this->sessionId, 'seek', position: 75);
        self::assertTrue($this->runtime->renew($old), 'A control must remain observable by its live worker.');
        $called = false;
        self::assertFalse($this->runtime->withOwnership($old, static function () use (&$called): void { $called = true; }));
        self::assertFalse($called);
        self::assertFalse($this->runtime->observe($old, 'ready', '/fixture/generation-' . $old->runtime->generation));
        $current = $this->runtime->refresh($old);
        self::assertNotNull($current);
        self::assertSame(75.0, $current->runtime->desired->position);
        self::assertGreaterThan($old->runtime->generation, $current->runtime->generation);
        self::assertTrue($this->runtime->withOwnership($current, function (): void {
            $this->first->executeStatement('UPDATE transcode_jobs SET completed_segments = 2 WHERE id = ?', [$this->jobId->toString()]);
        }));
        self::assertSame(2, (int) $this->second->fetchOne('SELECT completed_segments FROM transcode_jobs'));
    }
    /** @return iterable<string, array{string}> */
    public static function completedRestarts(): iterable
    {
        yield 'seek' => ['seek'];
        yield 'audio profile' => ['profile'];
    }

    #[DataProvider('completedRestarts')]
    public function testCompletedOutputRestartsForChangedSeekOrProfileWithoutTheOriginalStartupDeadline(string $control): void
    {
        $this->admit();
        $old = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($old);
        self::assertTrue($this->runtime->observe($old, 'complete', '/fixture/generation-' . $old->runtime->generation));
        $paused = $this->runtime->control($this->jobId, $this->sessionId, 'pause-complete', status: 'paused', seek: false);
        self::assertSame('complete', $paused->outcome);
        self::assertSame('paused', $paused->desired->status);
        $this->first->executeStatement("UPDATE transcode_runtime_state SET admission_deadline = clock_timestamp() - interval '1 second'");
        $restart = $control === 'seek'
            ? $this->runtime->control($this->jobId, $this->sessionId, 'seek-complete', position: 42)
            : $this->runtime->control($this->jobId, $this->sessionId, 'profile-complete', audioProfile: AudioProfile::mobileMono());
        self::assertSame('accepted', $restart->outcome);
        self::assertSame($old->runtime->generation + 1, $restart->generation);
        self::assertSame('paused', $restart->desired->status);
        self::assertNull($restart->outputDirectory);
        self::assertFalse($restart->ownershipLive);
        self::assertFalse($this->runtime->release($old));
        $successor = $this->runtimeFor($this->second)->claim($this->jobId, $this->worker('b'));
        self::assertNotNull($successor, 'A durable control restarts execution independently of the original startup deadline.');
        self::assertGreaterThan($restart->generation, $successor->runtime->generation);
        self::assertTrue($this->runtime->observe($successor, 'accepted'));
    }

    public function testCompletedCacheControlsKeepFilesAndGenerationUntilAnActualRestart(): void
    {
        $this->admit();
        $claim = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($claim);
        $directory = '/fixture/generation-' . $claim->runtime->generation;
        self::assertTrue($this->runtime->observe($claim, 'complete', $directory));
        $paused = $this->runtime->control($this->jobId, $this->sessionId, 'pause-complete', status: 'paused', position: 10, seek: false);
        self::assertSame('paused', $paused->desired->status);
        self::assertSame('complete', $paused->outcome);
        self::assertSame($directory, $paused->outputDirectory);
        self::assertSame($claim->runtime->generation, $paused->generation);
        $resumed = $this->runtime->control($this->jobId, $this->sessionId, 'resume-complete', status: 'running', position: 20, seek: false);
        self::assertSame('running', $resumed->desired->status);
        self::assertSame('complete', $resumed->outcome);
        self::assertSame($paused->desiredRevision + 1, $resumed->desiredRevision);
        self::assertSame($claim->runtime->generation, $resumed->generation);
        $unchanged = $this->runtime->control($this->jobId, $this->sessionId, 'same-profile-complete', audioProfile: AudioProfile::streamingStereo());
        self::assertSame($resumed->desiredRevision, $unchanged->desiredRevision);
        self::assertSame('complete', $unchanged->outcome);
    }

    public function testCancellationOfCompletedOutputIsTerminal(): void
    {
        $this->admit();
        $claim = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($claim);
        self::assertTrue($this->runtime->observe($claim, 'complete', '/fixture/generation-' . $claim->runtime->generation));
        $cancelled = $this->runtime->control($this->jobId, $this->sessionId, 'cancel-complete', status: 'cancelled');
        self::assertSame('cancelled', $cancelled->outcome);
        self::assertSame('cancelled', $cancelled->desired->status);
        self::assertNull($this->runtime->claim($this->jobId, $this->worker('b')));
        $ignored = $this->runtime->control($this->jobId, $this->sessionId, 'seek-cancelled-complete', position: 42);
        self::assertSame($cancelled->desiredRevision, $ignored->desiredRevision);
        self::assertSame('cancelled', $ignored->outcome);
    }

}
