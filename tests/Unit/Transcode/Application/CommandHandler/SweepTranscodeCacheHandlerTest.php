<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\CommandHandler\SweepTranscodeCacheHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for SweepTranscodeCacheHandler.
 *
 * Covers the six sweep-policy scenarios from the cache-sweep plan (R1–R4):
 * TTL eviction, active-job protection, active-session protection, TTL
 * retention, LRU size-budget eviction, and dry-run reporting. Uses a real
 * temp filesystem so file mtimes drive the TTL and LRU decisions exactly as
 * they do in production.
 */
final class SweepTranscodeCacheHandlerTest extends TestCase
{
    private string $cacheRoot;
    private TranscodeJobPortInterface $jobPort;
    private TranscodeSessionPortInterface $sessionPort;
    private TranscodeStoragePortInterface $storage;
    /** @var array<string, list<TranscodeJob>> */
    private array $jobsByVideo = [];
    /** @var array<string, list<TranscodeSession>> */
    private array $sessionsByJob = [];

    protected function setUp(): void
    {
        $this->cacheRoot = sys_get_temp_dir() . '/baander_sweep_' . bin2hex(random_bytes(8));
        @mkdir($this->cacheRoot, recursive: true);

        // Real storage implementation bound to the temp cache root so file
        // mtimes, directory sizes, and deletions behave exactly as in prod.
        $this->storage = $this->buildRealStorage();

        $this->jobPort = $this->createStub(TranscodeJobPortInterface::class);
        $this->sessionPort = $this->createStub(TranscodeSessionPortInterface::class);

        $this->jobPort->method('findActiveByVideo')->willReturnCallback(fn (Uuid $v) => $this->jobsByVideo[$v->toString()] ?? []);
        $this->sessionPort->method('findByJob')->willReturnCallback(function (Uuid $jobId) {
            return $this->sessionsByJob[$jobId->toString()] ?? [];
        });
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->cacheRoot);
    }

    public function testDeletesVideoDirectoryOlderThanTtl(): void
    {
        $videoId = Uuid::v7();
        $dir = $this->cacheRoot . '/' . $videoId->toString();
        $this->writeSegment($dir . '/p1080', 'v0_a0_p1080_0.m4s', "data", 3600 * 26); // 26h old

        $handler = $this->buildHandler();
        $result = $handler->sweep(['ttl_hours' => 24]);

        $this->assertContains($videoId->toString(), $result->deletedVideoIds);
        $this->assertFileDoesNotExist($dir);
        $this->assertSame(4, $result->bytesFreed);
        $this->assertFalse($result->dryRun);
    }

    public function testLinkedOutsideFileDoesNotExtendCacheLifetimeOrContributeBytes(): void
    {
        $videoId = Uuid::v7();
        $dir = $this->cacheRoot . '/' . $videoId->toString();
        $this->writeSegment($dir . '/p1080', 'old.m4s', 'data', 3600 * 26);
        $outside = $this->cacheRoot . '-outside';
        file_put_contents($outside, 'outside content');
        $link = $dir . '/p1080/recent.m4s';
        symlink($outside, $link);

        try {
            $result = $this->buildHandler()->sweep(['ttl_hours' => 24]);

            self::assertContains($videoId->toString(), $result->deletedVideoIds);
            self::assertSame(4, $result->bytesFreed);
            self::assertDirectoryDoesNotExist($dir);
            self::assertSame('outside content', file_get_contents($outside));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            unlink($outside);
        }
    }

    public function testDoesNotDeleteVideoDirectoryWithActiveJob(): void
    {
        $videoId = Uuid::v7();
        $dir = $this->cacheRoot . '/' . $videoId->toString();
        $this->writeSegment($dir . '/p1080', 'v0_a0_p1080_0.m4s', "data", 3600 * 26);

        $job = TranscodeJob::create($videoId, QualityTier::p1080(), $dir);
        $job->markInProgress(); // still encoding -> active
        $this->jobsByVideo[$videoId->toString()] = [$job];

        $handler = $this->buildHandler();
        $result = $handler->sweep(['ttl_hours' => 24]);

        $this->assertNotContains($videoId->toString(), $result->deletedVideoIds);
        $this->assertContains($videoId->toString(), $result->skippedActive);
        $this->assertDirectoryExists($dir);
    }

    public function testDoesNotDeleteVideoDirectoryWithRecentlyActiveSession(): void
    {
        $videoId = Uuid::v7();
        $dir = $this->cacheRoot . '/' . $videoId->toString();
        $this->writeSegment($dir . '/p1080', 'v0_a0_p1080_0.m4s', "data", 3600 * 26);

        // Completed job (not encoding) but a viewer is actively watching.
        $job = TranscodeJob::create($videoId, QualityTier::p1080(), $dir);
        $job->markInProgress();
        $job->markCompleted();
        $session = TranscodeSession::create(
            Uuid::v7(),
            $job->getId(),
            $videoId,
            AudioProfile::mobileStereo(),
            SessionPriority::Normal,
        );
        $session->markPreparing();
        $session->markActive(); // updatedAt = now -> within active window
        $this->sessionsByJob[$job->getId()->toString()] = [$session];
        $this->jobsByVideo[$videoId->toString()] = [$job];

        $handler = $this->buildHandler();
        $result = $handler->sweep(['ttl_hours' => 24]);

        $this->assertNotContains($videoId->toString(), $result->deletedVideoIds);
        $this->assertContains($videoId->toString(), $result->skippedActive);
        $this->assertDirectoryExists($dir);
    }

    public function testDoesNotDeleteVideoDirectoryNewerThanTtl(): void
    {
        $videoId = Uuid::v7();
        $dir = $this->cacheRoot . '/' . $videoId->toString();
        $this->writeSegment($dir . '/p1080', 'v0_a0_p1080_0.m4s', "data", 60); // 1 min old, no job/session

        $handler = $this->buildHandler();
        $result = $handler->sweep(['ttl_hours' => 24]);

        $this->assertNotContains($videoId->toString(), $result->deletedVideoIds);
        $this->assertDirectoryExists($dir);
    }

    public function testEvictsOldestDirectoriesFirstWhenOverSizeBudget(): void
    {
        $old = Uuid::v7();
        $mid = Uuid::v7();
        $new = Uuid::v7();

        // All three are newer than TTL (10 min old) so the TTL pass keeps them.
        // Budget is tiny (a few bytes) so LRU must evict oldest-first.
        $this->writeSegment($this->cacheRoot . '/' . $old->toString() . '/p1080', 'a.m4s', "old", 600);
        $this->writeSegment($this->cacheRoot . '/' . $mid->toString() . '/p1080', 'a.m4s', "mid", 300);
        $this->writeSegment($this->cacheRoot . '/' . $new->toString() . '/p1080', 'a.m4s', "new", 60);

        $handler = $this->buildHandler();
        // max_gb that resolves to ~0 bytes so LRU evicts everything oldest-first.
        $result = $handler->sweep(['ttl_hours' => 24, 'max_gb' => 0.0]);

        // Oldest (old) must be deleted before newest (new).
        $posOld = array_search($old->toString(), $result->deletedVideoIds, true);
        $posNew = array_search($new->toString(), $result->deletedVideoIds, true);
        $this->assertNotFalse($posOld);
        $this->assertNotFalse($posNew);
        $this->assertLessThan($posNew, $posOld, 'oldest video should be evicted before newest');
        $this->assertContains($old->toString(), $result->deletedVideoIds);
    }

    public function testSizeBudgetDoesNotEvictActiveDirectories(): void
    {
        $active = Uuid::v7();
        $idle = Uuid::v7();

        // Both newer than TTL; idle is newer but the active one must still survive budget pressure.
        $this->writeSegment($this->cacheRoot . '/' . $active->toString() . '/p1080', 'a.m4s', "activedata", 60);
        $this->writeSegment($this->cacheRoot . '/' . $idle->toString() . '/p1080', 'a.m4s', "idle", 600);

        $job = TranscodeJob::create($active, QualityTier::p1080(), $this->cacheRoot . '/' . $active->toString());
        $job->markInProgress();
        $this->jobsByVideo[$active->toString()] = [$job];

        $handler = $this->buildHandler();
        $result = $handler->sweep(['ttl_hours' => 24, 'max_gb' => 0.0]);

        $this->assertNotContains($active->toString(), $result->deletedVideoIds, 'active dir must survive budget pressure');
        $this->assertContains($idle->toString(), $result->deletedVideoIds);
    }

    public function testDryRunReportsDeletionsWithoutDeleting(): void
    {
        $videoId = Uuid::v7();
        $dir = $this->cacheRoot . '/' . $videoId->toString();
        $this->writeSegment($dir . '/p1080', 'v0_a0_p1080_0.m4s', "data", 3600 * 26);

        $handler = $this->buildHandler();
        $result = $handler->sweep(['ttl_hours' => 24, 'dry_run' => true]);

        $this->assertTrue($result->dryRun);
        $this->assertContains($videoId->toString(), $result->deletedVideoIds);
        $this->assertDirectoryExists($dir, 'dry-run must not delete anything');
    }

    // --- helpers -----------------------------------------------------------

    private function buildHandler(): SweepTranscodeCacheHandler
    {
        return new SweepTranscodeCacheHandler($this->storage, $this->jobPort, $this->sessionPort);
    }

    private function buildRealStorage(): TranscodeStoragePortInterface
    {
        return new TranscodeFileStorage(new SegmentFileResolver($this->cacheRoot));
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            !$file->isLink() && $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }

    /**
     * Create a segment file with $content and backdate its mtime by
     * $ageSeconds to simulate idle cache content.
     */
    private function writeSegment(string $dir, string $name, string $content, int $ageSeconds): void
    {
        @mkdir($dir, recursive: true);
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);
        $mtime = time() - $ageSeconds;
        touch($path, $mtime);
    }
}
