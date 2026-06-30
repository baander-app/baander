<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeJobState;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Infrastructure\Swoole\JobStatePersister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

final class JobStatePersisterTest extends TestCase
{
    private TranscodeJobRepositoryInterface&MockObject $jobRepository;
    private TranscodeStoragePortInterface&MockObject $storage;
    private LoggerInterface&MockObject $logger;
    private string $stateDir;

    protected function setUp(): void
    {
        $this->jobRepository = $this->createMock(TranscodeJobRepositoryInterface::class);
        $this->storage = $this->createMock(TranscodeStoragePortInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->stateDir = sys_get_temp_dir() . '/baander_test_job_state_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stateDir)) {
            $this->removeDirectory($this->stateDir);
        }
    }

    public function testPersistAndLoadRoundTripsCorrectly(): void
    {
        $this->logger->expects($this->once())->method('debug');
        $this->storage->method('exists')->willReturn(true);

        $job = $this->createInProgressJob();
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        $persister->persist($job);

        $loaded = $persister->load($job->getPublicId());

        $this->assertNotNull($loaded);
        $this->assertSame($job->getId()->toString(), $loaded['jobId']);
        $this->assertSame($job->getVideoId()->toString(), $loaded['videoId']);
        $this->assertSame('720p', $loaded['qualityTier']);
        $this->assertSame('in_progress', $loaded['status']);
        $this->assertSame(10, $loaded['totalSegments']);
        $this->assertCount(3, $loaded['completedSegments']);
        $this->assertSame(3, $loaded['currentSegmentIndex']);
    }

    public function testListPersistedJobsReturnsJobIds(): void
    {
        $job1 = $this->createInProgressJob();
        $job2 = $this->createInProgressJob();
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        $persister->persist($job1);
        $persister->persist($job2);

        $ids = $persister->listPersistedJobs();

        $this->assertCount(2, $ids);
        $this->assertContains($job1->getPublicId()->toString(), $ids);
        $this->assertContains($job2->getPublicId()->toString(), $ids);
    }

    public function testCleanupRemovesStateFile(): void
    {
        $job = $this->createInProgressJob();
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        $persister->persist($job);

        $this->assertNotNull($persister->load($job->getPublicId()), 'State should exist before cleanup');

        $persister->cleanup($job->getPublicId());

        $this->assertNull($persister->load($job->getPublicId()), 'State should be gone after cleanup');
    }

    public function testLoadWithNonExistentFileReturnsNull(): void
    {
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        $result = $persister->load(new PublicId());

        $this->assertNull($result);
    }

    public function testPersistForCompletedJobIsNoOp(): void
    {
        $job = TranscodeJob::create(Uuid::v4(), QualityTier::p720(), '/tmp/output');
        $job->markInProgress();
        $job->markSegmentCompleted(0, '/tmp/output/seg0.m4s', 1000, 2.0);
        $job->markCompleted();

        $this->logger->expects($this->never())->method('debug');

        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());
        $persister->persist($job);

        $files = glob($this->stateDir . '/*.json');
        $this->assertSame([], $files, 'No state file should be written for a completed job');
    }

    public function testPersistForFailedJobIsNoOp(): void
    {
        $job = TranscodeJob::create(Uuid::v4(), QualityTier::p720(), '/tmp/output');
        $job->markInProgress();
        $job->markFailed('encoder crashed');

        $this->logger->expects($this->never())->method('debug');

        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());
        $persister->persist($job);

        $files = glob($this->stateDir . '/*.json');
        $this->assertSame([], $files, 'No state file should be written for a failed job');
    }

    public function testPersistForCancelledJobIsNoOp(): void
    {
        $job = TranscodeJob::create(Uuid::v4(), QualityTier::p720(), '/tmp/output');
        $job->markCancelled();

        $this->logger->expects($this->never())->method('debug');

        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());
        $persister->persist($job);

        $files = glob($this->stateDir . '/*.json');
        $this->assertSame([], $files, 'No state file should be written for a cancelled job');
    }

    public function testLoadThrowsOnCorruptedJsonFile(): void
    {
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());
        $publicId = new PublicId();

        // Write a corrupted JSON file
        $filePath = sprintf('%s/%s.json', $this->stateDir, $publicId->toString());
        file_put_contents($filePath, '{not valid json!!!');

        $this->expectException(NotEncodableValueException::class);

        $persister->load($publicId);
    }

    public function testConstructorCreatesDirectoryIfNotExists(): void
    {
        $this->assertFalse(is_dir($this->stateDir));

        new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        $this->assertTrue(is_dir($this->stateDir));
    }

    public function testCleanupForNonExistentFileDoesNotThrow(): void
    {
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        // Should not throw even if the file doesn't exist
        $persister->cleanup(new PublicId());

        $this->assertTrue(true); // Reached without exception
    }

    public function testListPersistedJobsReturnsEmptyWhenNoFiles(): void
    {
        $persister = new JobStatePersister($this->jobRepository, $this->storage, $this->logger, $this->stateDir, new JsonEncoder());

        $this->assertSame([], $persister->listPersistedJobs());
    }

    private function createInProgressJob(): TranscodeJob
    {
        $job = TranscodeJob::create(Uuid::v4(), QualityTier::p720(), '/tmp/output');
        $job->markInProgress();
        $job->setTotalSegments(10);
        $job->markSegmentCompleted(0, '/tmp/output/seg0.m4s', 5000, 2.0);
        $job->markSegmentCompleted(1, '/tmp/output/seg1.m4s', 4800, 2.0);
        $job->markSegmentCompleted(2, '/tmp/output/seg2.m4s', 5200, 2.0);

        return $job;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
