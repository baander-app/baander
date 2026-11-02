<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
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
        $service = new TranscodeJobService($repository, $storage, $this->createStub(EventDispatcherInterface::class));

        self::assertSame($deleted ? 1 : 0, $service->cleanupOrphanedJobs());
    }
}
