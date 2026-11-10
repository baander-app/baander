<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $this->createStub(TranscodeExecutionOwnershipPort::class));

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
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class), $ownership);

        self::assertSame($live ? 0 : 1, $service->cleanupOrphanedJobs());
    }
}
