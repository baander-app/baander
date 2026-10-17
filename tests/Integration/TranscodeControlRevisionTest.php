<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
}
