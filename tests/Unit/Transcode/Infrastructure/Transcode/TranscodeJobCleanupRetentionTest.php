<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Exception\TranscodeCleanupIncompleteException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TranscodeJobCleanupRetentionTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function retentionStates(): iterable
    {
        yield 'retained' => [false];
        yield 'unretained' => [true];
    }

    #[DataProvider('retentionStates')]
    public function testOrphanRecordIsRemovedOnlyAfterConditionalStorageDeletion(bool $deleted): void
    {
        $job = TranscodeJob::create(Uuid::v7(), QualityTier::p720(), '/cache/video/job', Uuid::v7());
        $repository = $this->createMock(TranscodeJobRepositoryInterface::class);
        $repository->method('findOrphanedJobs')->willReturn([$job]);
        $repository->expects($deleted ? self::once() : self::never())->method('delete')->with($job);
        $storage = $this->createMock(TranscodeStoragePortInterface::class);
        $storage->expects(self::once())->method('deleteIfUnretained')->with('/cache/video/job')->willReturn($deleted);
        $storage->expects(self::never())->method('deleteDirectory');
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $this->createStub(TranscodeExecutionOwnershipPort::class), $this->transaction(), new NullLogger());

        self::assertSame($deleted ? 1 : 0, $service->cleanupOrphanedJobs());
    }

    /** @return iterable<string, array{bool}> */
    public static function workerOwnership(): iterable
    {
        yield 'a worker still owns the execution' => [true];
        yield 'the worker has let go' => [false];
    }

    #[DataProvider('workerOwnership')]
    public function testOrphanStaysWhileAWorkerStillOwnsItsExecution(bool $live): void
    {
        $job = TranscodeJob::create(Uuid::v7(), QualityTier::p720(), '/cache/video/job', Uuid::v7());
        $repository = $this->createMock(TranscodeJobRepositoryInterface::class);
        $repository->method('findOrphanedJobs')->willReturn([$job]);
        $repository->expects($live ? self::never() : self::once())->method('delete')->with($job);
        $storage = $this->createMock(TranscodeStoragePortInterface::class);
        // The worker may still be writing: its output is not even offered for deletion.
        $storage->expects($live ? self::never() : self::once())->method('deleteIfUnretained')->willReturn(true);
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturn(new TranscodeRuntimeSnapshot($job->getId(), Uuid::v7(), Uuid::v7()->toString(),
            new DesiredTranscodeState(AudioProfile::streamingStereo(), status: 'cancelled'), 2, 1, 1, 'cancelled', null, null, $live));
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $ownership, $this->transaction(), new NullLogger());

        self::assertSame($live ? 0 : 1, $service->cleanupOrphanedJobs());
    }

    public function testAnOutputThatCannotBeDeletedIsLoggedAndKeepsItsRecord(): void
    {
        $stuck = TranscodeJob::create(Uuid::v7(), QualityTier::p720(), '/cache/video/stuck', Uuid::v7());
        $free = TranscodeJob::create(Uuid::v7(), QualityTier::p720(), '/cache/video/free', Uuid::v7());
        $failure = new \RuntimeException('The directory is not writable.');
        $repository = $this->createMock(TranscodeJobRepositoryInterface::class);
        $repository->method('findOrphanedJobs')->willReturn([$stuck, $free]);
        // The record is how a later run finds the output that stayed.
        $repository->expects(self::once())->method('delete')->with($free);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('deleteIfUnretained')->willReturnCallback(static fn (string $directory): bool => $directory === '/cache/video/stuck' ? throw $failure : true);
        $logger = $this->logger();
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $this->createStub(TranscodeExecutionOwnershipPort::class), $this->transaction(), $logger);

        try {
            $service->cleanupOrphanedJobs();
            self::fail('A run that left a job behind after an error does not report success.');
        } catch (TranscodeCleanupIncompleteException $incomplete) {
            self::assertSame([1, 1], [$incomplete->removed, $incomplete->failed]);
        }
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame(['job' => $stuck->getId()->toString(), 'step' => 'output deletion', 'exception' => $failure], $logger->records[0]['context']);
    }

    public function testARecordThatCannotBeRemovedDoesNotKeepTheOtherJobs(): void
    {
        $jobs = [
            TranscodeJob::create(Uuid::v7(), QualityTier::p360(), '/cache/video/first', Uuid::v7()),
            TranscodeJob::create(Uuid::v7(), QualityTier::p720(), '/cache/video/second', Uuid::v7()),
            TranscodeJob::create(Uuid::v7(), QualityTier::p1080(), '/cache/video/third', Uuid::v7()),
        ];
        $failure = new \RuntimeException('The row is locked.');
        $attempted = [];
        $repository = $this->createStub(TranscodeJobRepositoryInterface::class);
        $repository->method('findOrphanedJobs')->willReturn($jobs);
        $repository->method('delete')->willReturnCallback(static function (TranscodeJob $job) use (&$attempted, $jobs, $failure): void {
            $attempted[] = $job;
            if ($job === $jobs[1]) {
                throw $failure;
            }
        });
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('deleteIfUnretained')->willReturn(true);
        // Each removal runs in a transaction of its own, so a failed one is rolled back alone.
        $transactions = 0;
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static function (callable $operation) use (&$transactions): mixed {
            ++$transactions;

            return $operation();
        });
        $logger = $this->logger();
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $this->createStub(TranscodeExecutionOwnershipPort::class), $transaction, $logger);

        try {
            $service->cleanupOrphanedJobs();
            self::fail('A run that could not remove a job does not report success.');
        } catch (TranscodeCleanupIncompleteException $incomplete) {
            self::assertSame([2, 1], [$incomplete->removed, $incomplete->failed]);
            self::assertSame('The transcode job cleanup removed 2 orphaned jobs and could not remove 1; the log names each job that failed.', $incomplete->getMessage());
        }
        self::assertSame($jobs, $attempted);
        self::assertSame(3, $transactions);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame(['job' => $jobs[1]->getId()->toString(), 'step' => 'record removal', 'exception' => $failure], $logger->records[0]['context']);
    }

    public function testALeaseThatCannotBeReadKeepsItsJobAndNotTheOthers(): void
    {
        $jobs = [
            TranscodeJob::create(Uuid::v7(), QualityTier::p360(), '/cache/video/first', Uuid::v7()),
            TranscodeJob::create(Uuid::v7(), QualityTier::p720(), '/cache/video/unreadable', Uuid::v7()),
            TranscodeJob::create(Uuid::v7(), QualityTier::p1080(), '/cache/video/third', Uuid::v7()),
        ];
        $failure = new \RuntimeException('The runtime state could not be read.');
        $removed = [];
        $repository = $this->createStub(TranscodeJobRepositoryInterface::class);
        $repository->method('findOrphanedJobs')->willReturn($jobs);
        $repository->method('delete')->willReturnCallback(static function (TranscodeJob $job) use (&$removed): void {
            $removed[] = $job;
        });
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(static fn (Uuid $jobId): ?TranscodeRuntimeSnapshot => $jobId->equals($jobs[1]->getId()) ? throw $failure : null);
        // Without a lease verdict a worker may still be writing: the output is not offered for deletion.
        $offered = [];
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('deleteIfUnretained')->willReturnCallback(static function (string $directory) use (&$offered): bool {
            $offered[] = $directory;

            return true;
        });
        $logger = $this->logger();
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $ownership, $this->transaction(), $logger);

        try {
            $service->cleanupOrphanedJobs();
            self::fail('A run that skipped a job after an error does not report success.');
        } catch (TranscodeCleanupIncompleteException $incomplete) {
            self::assertSame([2, 1], [$incomplete->removed, $incomplete->failed]);
        }
        self::assertSame(['/cache/video/first', '/cache/video/third'], $offered);
        self::assertSame([$jobs[0], $jobs[2]], $removed);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame(['job' => $jobs[1]->getId()->toString(), 'step' => 'lease check', 'exception' => $failure], $logger->records[0]['context']);
    }

    public function testTheRunAfterAFailedRecordRemovalOffersTheMissingOutputAgainAndRemovesTheJob(): void
    {
        $root = sys_get_temp_dir() . '/baander-cleanup-retry-' . bin2hex(random_bytes(8));
        $output = $root . '/video/720p/job-one';
        mkdir($output . '/generation-1', 0700, true);
        file_put_contents($output . '/generation-1/segment.m4s', 'media');
        try {
            $job = TranscodeJob::create(Uuid::v7(), QualityTier::p720(), $output, Uuid::v7());
            // The first removal of the record fails; later ones succeed.
            $removals = new \ArrayObject();
            $repository = $this->createStub(TranscodeJobRepositoryInterface::class);
            $repository->method('findOrphanedJobs')->willReturn([$job]);
            $repository->method('delete')->willReturnCallback(static function (TranscodeJob $job) use ($removals): void {
                $removals->append($job);
                if (count($removals) === 1) {
                    throw new \RuntimeException('The row is locked.');
                }
            });
            $service = new TranscodeJobService($repository, new TranscodeFileStorage(new SegmentFileResolver($root)), $this->createStub(EventDispatcherInterface::class), $this->createStub(TranscodeExecutionOwnershipPort::class), $this->transaction(), new NullLogger());

            try {
                $service->cleanupOrphanedJobs();
                self::fail('A run that could not remove the record does not report success.');
            } catch (TranscodeCleanupIncompleteException $incomplete) {
                self::assertSame([0, 1], [$incomplete->removed, $incomplete->failed]);
            }
            // The record outlived its output. Deleting output that is already gone succeeds,
            // so the next run reaches the record again.
            self::assertDirectoryDoesNotExist($output);

            self::assertSame(1, $service->cleanupOrphanedJobs());
            self::assertCount(2, $removals);
        } finally {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    /** A transaction boundary that only runs its operation. */
    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $transaction;
    }

    /** @return AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<mixed>}>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
