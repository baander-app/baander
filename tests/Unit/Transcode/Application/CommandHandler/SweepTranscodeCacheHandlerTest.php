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
    private array $jobsByVideo = [];
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
        $this->jobsByVideo = [];

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
        return new class($this->cacheRoot) implements TranscodeStoragePortInterface {
            public function __construct(private readonly string $root)
            {
            }

            public function resolveJobDirectory(Uuid $videoId, QualityTier $qualityTier): string
            {
                return sprintf('%s/%s/%s', $this->root, $videoId->toString(), $qualityTier->name);
            }

            public function resolveInitSegmentPath(Uuid $videoId, QualityTier $qualityTier): string
            {
                return $this->resolveJobDirectory($videoId, $qualityTier) . '/init.mp4';
            }

            public function resolveSegmentPath(Uuid $videoId, QualityTier $qualityTier, int $segmentIndex): string
            {
                return sprintf('%s/%s_%d.m4s', $this->resolveJobDirectory($videoId, $qualityTier), $qualityTier->name, $segmentIndex);
            }

            public function resolveAudioDirectory(Uuid $videoId, string $language): string
            {
                return sprintf('%s/%s/audio/%s', $this->root, $videoId->toString(), $language);
            }

            public function resolveAudioInitSegmentPath(Uuid $videoId, string $language): string
            {
                return $this->resolveAudioDirectory($videoId, $language) . '/init.mp4';
            }

            public function resolveAudioSegmentPath(Uuid $videoId, string $language, int $segmentIndex): string
            {
                return sprintf('%s/seg_%d.m4s', $this->resolveAudioDirectory($videoId, $language), $segmentIndex);
            }

            public function resolveSubtitleDirectory(Uuid $videoId, string $language): string
            {
                return sprintf('%s/%s/subtitles/%s', $this->root, $videoId->toString(), $language);
            }

            public function resolveSubtitleSegmentPath(Uuid $videoId, string $language, string $segmentName): string
            {
                return sprintf('%s/%s.vtt', $this->resolveSubtitleDirectory($videoId, $language), $segmentName);
            }

            public function exists(string $path): bool
            {
                return file_exists($path);
            }

            public function deleteDirectory(string $path): void
            {
                if (!is_dir($path)) {
                    return;
                }
                $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
                }
                rmdir($path);
            }

            public function getDirectorySize(string $path): int
            {
                if (!is_dir($path)) {
                    return 0;
                }
                $size = 0;
                $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new \RecursiveIteratorIterator($it);
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                }
                return $size;
            }

            public function getBasePath(): string
            {
                return $this->root;
            }

            public function getVideoDirectories(): array
            {
                if (!is_dir($this->root)) {
                    return [];
                }
                $dirs = [];
                foreach (scandir($this->root) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (is_dir($this->root . '/' . $entry)) {
                        $dirs[] = $entry;
                    }
                }
                return $dirs;
            }
        };
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
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
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
