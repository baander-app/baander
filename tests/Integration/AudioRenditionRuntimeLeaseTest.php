<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use PHPUnit\Framework\TestCase;

final class AudioRenditionRuntimeLeaseTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    private function input(string $signature = 'track-123.opus'): AudioRenditionInput
    {
        return new AudioRenditionInput('baander-track', $signature, '/fixture/source.flac', AudioRenditionFormat::Opus, 128000, '/fixture/derived/track');
    }

    public function testConcurrentAdmissionConvergesOnTheEntireCanonicalIdentity(): void
    {
        $first = new AudioRenditionRuntime($this->first);
        $second = new AudioRenditionRuntime($this->second);
        $input = $this->input();
        $a = $first->admit($input);
        $b = $second->admit($input);
        self::assertSame($a->key, $b->key);
        self::assertSame(1, (int) $this->first->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
        self::assertNotSame($a->key, $first->admit($this->input('source-changed.opus'))->key);
        self::assertCount(2, $second->pending());
        $claim = $first->claim($a->key, $this->worker());
        self::assertNotNull($claim);
        self::assertNull($second->claim($a->key, $this->worker('b')));
    }

    public function testBusyFilesystemLockCanDeferWithoutPublishingAPathAndRetryUsesAnotherGeneration(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $row = $runtime->admit($this->input());
        $old = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($old);
        self::assertTrue($runtime->defer($old));
        self::assertNull($runtime->find($row->key)?->partialPath);
        $next = $runtime->claim($row->key, $this->worker('b'));
        self::assertNotNull($next);
        self::assertGreaterThan($old->runtime->generation, $next->runtime->generation);
        self::assertFalse($runtime->fail($old, 'late error'));
        $directory = $next->runtime->generationDirectory();
        self::assertTrue($runtime->start($next, $directory . '/audio.partial', $directory . '/audio.opus'));
        self::assertTrue($runtime->complete($next));
        self::assertSame('complete', $runtime->find($row->key)->state);
        self::assertSame($directory . '/audio.opus', $runtime->find($row->key)->finalPath);
        self::assertFalse($runtime->defer($next), 'Completed output is terminal.');
    }

    public function testExpiredRunningWorkIsRecoveredAndStalePublicationIsFenced(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $row = $runtime->admit($this->input());
        $old = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($old);
        $directory = $old->runtime->generationDirectory();
        self::assertTrue($runtime->start($old, $directory . '/audio.partial', $directory . '/audio.opus'));
        $this->first->executeStatement("UPDATE audio_rendition_runtime SET lease_expires_at = clock_timestamp() - interval '1 second'");
        self::assertFalse($runtime->find($row->key)->ownershipLive);
        self::assertCount(1, $runtime->pending());
        $new = $runtime->claim($row->key, $this->worker('b'));
        self::assertNotNull($new);
        self::assertFalse($runtime->renew($old));
        self::assertFalse($runtime->complete($old));
        self::assertFalse($runtime->fail($old, 'stale failure'));
        self::assertFalse($runtime->defer($old));
        self::assertFalse($runtime->withOwnership($old, static function (): void {
            self::fail('A stale owner cannot enter the publication callback.');
        }));
        self::assertTrue($runtime->renew($new));
        self::assertNotSame($directory, $new->runtime->generationDirectory());
    }

    public function testFinalRenameAndCompletionShareTheLiveOwnershipTransaction(): void
    {
        $directory = sys_get_temp_dir() . '/baander-audio-publication-' . bin2hex(random_bytes(8));
        $runtime = new AudioRenditionRuntime($this->first);
        $input = new AudioRenditionInput('baander-track', 'track-123.opus', '/fixture/source.flac', AudioRenditionFormat::Opus, 128000, $directory);
        $row = $runtime->admit($input);
        $claim = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($claim);
        $generationDirectory = $claim->runtime->generationDirectory();
        mkdir($generationDirectory, 0700, true);
        $partial = $generationDirectory . '/audio.partial';
        $final = $generationDirectory . '/audio.opus';
        file_put_contents($partial, 'fixture audio');
        try {
            self::assertTrue($runtime->start($claim, $partial, $final));
            self::assertTrue($runtime->withOwnership($claim, function () use ($runtime, $claim, $partial, $final): void {
                self::assertTrue(rename($partial, $final));
                self::assertTrue($runtime->complete($claim));
                self::assertSame('running', $this->second->fetchOne('SELECT state FROM audio_rendition_runtime'), 'Terminal publication is not visible before the ownership transaction commits.');
            }));
            self::assertFileExists($final);
            self::assertSame('complete', $runtime->find($row->key)->state);
            self::assertSame($final, $runtime->find($row->key)->finalPath);
            self::assertFalse($runtime->withOwnership($claim, static function (): void {
                self::fail('Completed output cannot be published again.');
            }));
        } finally {
            foreach ([$partial, $final] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($generationDirectory);
            rmdir($directory);
        }
    }

    public function testCallbackFailureRollsBackTerminalPublicationAndPropagates(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $row = $runtime->admit($this->input());
        $claim = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($claim);
        $directory = $claim->runtime->generationDirectory();
        self::assertTrue($runtime->start($claim, $directory . '/audio.partial', $directory . '/audio.opus'));
        $failure = new \RuntimeException('Publication callback failed');
        $caught = null;
        try {
            $runtime->withOwnership($claim, static function () use ($runtime, $claim, $failure): void {
                self::assertTrue($runtime->complete($claim));
                throw $failure;
            });
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);
        self::assertSame('running', $runtime->find($row->key)->state);
        self::assertTrue($runtime->renew($claim));
    }

    public function testLeaseLossDuringCallbackRollsBackTerminalPublication(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $row = $runtime->admit($this->input());
        $claim = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($claim);
        $directory = $claim->runtime->generationDirectory();
        self::assertTrue($runtime->start($claim, $directory . '/audio.partial', $directory . '/audio.opus'));
        self::assertFalse($runtime->withOwnership($claim, function () use ($runtime, $claim): void {
            self::assertTrue($runtime->complete($claim));
            $this->first->executeStatement("UPDATE audio_rendition_runtime SET lease_expires_at = clock_timestamp() - interval '1 second'");
        }));
        self::assertSame('running', $this->second->fetchOne('SELECT state FROM audio_rendition_runtime'));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
    }

    public function testEvictedCompletedOutputCanBeResetOnlyForTheObservedGeneration(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $other = new AudioRenditionRuntime($this->second);
        $row = $runtime->admit($this->input());
        $old = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($old);
        $directory = $old->runtime->generationDirectory();
        self::assertTrue($runtime->start($old, $directory . '/audio.partial', $directory . '/audio.opus'));
        self::assertTrue($runtime->complete($old));
        self::assertFalse($other->resetCompleted($row->key, $old->runtime->generation + 1));
        self::assertTrue($other->resetCompleted($row->key, $old->runtime->generation));
        $pending = $runtime->find($row->key);
        self::assertNotNull($pending);
        self::assertSame('pending', $pending->state);
        self::assertSame($old->runtime->generation, $pending->generation);
        self::assertNull($pending->partialPath);
        self::assertNull($pending->finalPath);
        self::assertNull($pending->error);
        self::assertFalse($pending->ownershipLive);
        self::assertFalse($runtime->resetCompleted($row->key, $old->runtime->generation));
        $next = $runtime->claim($row->key, $this->worker('b'));
        self::assertNotNull($next);
        self::assertGreaterThan($old->runtime->generation, $next->runtime->generation);
        self::assertFalse($other->resetCompleted($row->key, $old->runtime->generation));
        self::assertFalse($runtime->complete($old));
        self::assertTrue($runtime->renew($next));
    }

    public function testPublicationOutsideTheGenerationDirectoryIsRejected(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $row = $runtime->admit($this->input());
        $claim = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($claim);
        $this->expectException(\InvalidArgumentException::class);
        $runtime->start($claim, '/fixture/shared.partial', '/fixture/shared.opus');
    }
}
