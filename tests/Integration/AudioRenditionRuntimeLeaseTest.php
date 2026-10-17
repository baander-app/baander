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
        self::assertTrue($runtime->renew($new));
        self::assertNotSame($directory, $new->runtime->generationDirectory());
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
