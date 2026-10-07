<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\JobStatus;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobMonitorFilter;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[AllowMockObjectsWithoutExpectations]
#[IgnoreDeprecations]
final class JobMonitorServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private CursorPaginator $cursorPaginator;
    private JobMonitorService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->cursorPaginator = new CursorPaginator();
        $this->service = new JobMonitorService($this->entityManager, $this->cursorPaginator, new JsonEncoder());
    }

    // ── JobMonitorFilter DTO tests ────────────────────────────────────────

    public function testFilterWithAllNullValues(): void
    {
        $filter = new JobMonitorFilter();

        $this->assertNull($filter->status);
        $this->assertNull($filter->name);
        $this->assertNull($filter->queue);
    }

    public function testFilterWithStatusOnly(): void
    {
        $filter = new JobMonitorFilter(status: 'running');

        $this->assertSame('running', $filter->status);
        $this->assertNull($filter->name);
        $this->assertNull($filter->queue);
    }

    public function testFilterWithNameOnly(): void
    {
        $filter = new JobMonitorFilter(name: 'ExtractAlbumCover');

        $this->assertNull($filter->status);
        $this->assertSame('ExtractAlbumCover', $filter->name);
        $this->assertNull($filter->queue);
    }

    public function testFilterWithQueueOnly(): void
    {
        $filter = new JobMonitorFilter(queue: 'async');

        $this->assertNull($filter->status);
        $this->assertNull($filter->name);
        $this->assertSame('async', $filter->queue);
    }

    public function testFilterWithAllValues(): void
    {
        $filter = new JobMonitorFilter(
            status: 'failed',
            name: 'ExtractAlbumCoverCommand',
            queue: 'async',
        );

        $this->assertSame('failed', $filter->status);
        $this->assertSame('ExtractAlbumCoverCommand', $filter->name);
        $this->assertSame('async', $filter->queue);
    }

    public function testFilterIsReadonly(): void
    {
        $filter = new JobMonitorFilter(status: 'queued');

        $reflection = new \ReflectionClass($filter);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }
    }

    // ── findByJobIdOrFail tests ───────────────────────────────────────────

    public function testFindByJobIdOrFailThrowsWhenNotFound(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->with(['jobId' => 'non-existent'])
            ->willReturn(null);

        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job monitor with jobId "non-existent" not found.');

        $this->service->findByJobIdOrFail('non-existent');
    }

    public function testFindByJobIdOrFailReturnsEntityWhenFound(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123', name: 'TestJob');
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->with(['jobId' => 'job-123'])
            ->willReturn($entity);

        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $result = $this->service->findByJobIdOrFail('job-123');

        $this->assertSame($entity, $result);
        $this->assertSame('job-123', $result->getJobId());
    }

    // ── markCancelled tests ──────────────────────────────────────────────

    public function testMarkCancelledFinishesNoEarlierThanTheStartAndDoesNotFlush(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('finished_at = GREATEST(CAST(:now AS TIMESTAMPTZ), started_at)'),
                $this->callback(static fn (array $params): bool => $params['status'] === JobStatus::Cancelled->value
                    && $params['job_id'] === 'job-cancel'
                    && isset($params['now'])),
            );

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->expects($this->never())->method('flush');

        $this->service->markCancelled('job-cancel');
    }

    public function testStartAttemptUpsertsTheJobRowAndReturnsItsAttempt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('ON CONFLICT (job_id) DO UPDATE'),
                    $this->stringContains('finished_at = NULL'),
                    $this->stringContains('attempt = job_monitors.attempt + 1'),
                    $this->stringContains('RETURNING attempt'),
                ),
                $this->callback(static fn (array $params): bool => $params['job_id'] === 'job-start'
                    && $params['status'] === JobStatus::Running->value
                    && $params['name'] === 'ExtractAlbumCoverCommand'
                    && $params['queue'] === 'async'
                    && $params['data'] === '{}'
                    && $params['data_truncated'] === false),
            )
            ->willReturn(2);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->expects($this->never())->method('flush');

        self::assertSame(2, $this->service->startAttempt('job-start', 'ExtractAlbumCoverCommand', 'async', '{}', false));
    }

    public function testMarkFinishedUpdatesOnlyTheGivenAttempt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('WHERE job_id = :job_id AND attempt = :attempt'),
                    $this->stringContains('finished_at = GREATEST(CAST(:now AS TIMESTAMPTZ), started_at)'),
                ),
                $this->callback(static fn (array $params): bool => $params['status'] === JobStatus::Finished->value
                    && $params['job_id'] === 'job-finish'
                    && $params['attempt'] === 2
                    && $params['exception'] === null
                    && $params['exception_class'] === null),
            );

        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->service->markFinished('job-finish', 2);
    }

    public function testMarkFailedRecordsTheErrorOfTheGivenAttempt(): void
    {
        $exception = new RuntimeException('Something went wrong');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('WHERE job_id = :job_id AND attempt = :attempt'),
                $this->callback(static function (array $params): bool {
                    $exceptionData = json_decode($params['exception'], true);

                    return $params['status'] === JobStatus::Failed->value
                        && $params['exception_class'] === RuntimeException::class
                        && $exceptionData['message'] === 'Something went wrong'
                        && $params['job_id'] === 'job-fail'
                        && $params['attempt'] === 1;
                }),
            );

        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->service->markFailed('job-fail', 1, $exception);
    }

    public function testSetProgressUsesDbalUpdate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('update')
            ->with(
                'job_monitors',
                $this->callback(static function (array $data): bool {
                    return $data['progress'] === 42 && isset($data['updated_at']);
                }),
                ['job_id' => 'job-progress'],
            );

        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->service->setProgress('job-progress', 42);
    }

    // ── appendAuditLog tests ─────────────────────────────────────────────

    public function testAppendAuditLogCreatesNewLogWhenNull(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-audit');
        $this->assertNull($entity->getAuditLog());

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->with(['jobId' => 'job-audit'])
            ->willReturn($entity);

        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $entry = ['action' => 'test', 'userId' => 'user-1'];
        $this->service->appendAuditLog('job-audit', $entry);

        $log = $this->readAuditLog($entity);
        $this->assertCount(1, $log);
        $this->assertSame('test', $log[0]['action']);
        $this->assertSame('user-1', $log[0]['userId']);
    }

    public function testAppendAuditLogAppendsToExistingLog(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-audit-2');
        $existingEntry = ['action' => 'first', 'at' => '2026-04-19T10:00:00+00:00'];
        $entity->setAuditLog(json_encode([$existingEntry]));

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->with(['jobId' => 'job-audit-2'])
            ->willReturn($entity);

        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $newEntry = ['action' => 'second', 'userId' => 'user-2'];
        $this->service->appendAuditLog('job-audit-2', $newEntry);

        $log = $this->readAuditLog($entity);
        $this->assertCount(2, $log);
        $this->assertSame('first', $log[0]['action']);
        $this->assertSame('second', $log[1]['action']);
    }

    public function testAppendAuditLogThrowsWhenNotFound(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->expectException(RuntimeException::class);

        $this->service->appendAuditLog('non-existent', ['action' => 'test']);
    }

    // ── markRetriedWithAudit tests ───────────────────────────────────────

    public function testMarkRetriedWithAuditSetsRetriedAndAppendsLog(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-retry');

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->exactly(2))->method('findOneBy')
            ->with(['jobId' => 'job-retry'])
            ->willReturn($entity);

        $this->entityManager->expects($this->exactly(2))->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->assertFalse($entity->isRetried());
        $this->assertNull($entity->getAuditLog());

        $this->service->markRetriedWithAudit('job-retry', 'new-job-456', 'user-99');

        $this->assertTrue($entity->isRetried());

        $log = $this->readAuditLog($entity);
        $this->assertCount(1, $log);
        $this->assertSame('retry', $log[0]['action']);
        $this->assertSame('new-job-456', $log[0]['newJobId']);
        $this->assertSame('user-99', $log[0]['userId']);
        $this->assertArrayHasKey('at', $log[0]);
    }

    public function testMarkRetriedWithAuditAppendsToExistingLog(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-retry-2');
        $entity->setAuditLog(json_encode([['action' => 'previous']]));

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->exactly(2))->method('findOneBy')
            ->with(['jobId' => 'job-retry-2'])
            ->willReturn($entity);

        $this->entityManager->expects($this->exactly(2))->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->markRetriedWithAudit('job-retry-2', 'new-job-789', 'user-42');

        $this->assertTrue($entity->isRetried());

        $log = $this->readAuditLog($entity);
        $this->assertCount(2, $log);
        $this->assertSame('previous', $log[0]['action']);
        $this->assertSame('retry', $log[1]['action']);
    }

    public function testMarkRetriedWithAuditThrowsWhenNotFound(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->expectException(RuntimeException::class);

        $this->service->markRetriedWithAudit('non-existent', 'new-job', 'user-1');
    }

    /** @return array<array-key, mixed> */
    private function readAuditLog(JobMonitorEntity $entity): array
    {
        $auditLog = $entity->getAuditLog();
        self::assertNotNull($auditLog);
        $entries = json_decode($auditLog, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);

        return $entries;
    }

}
