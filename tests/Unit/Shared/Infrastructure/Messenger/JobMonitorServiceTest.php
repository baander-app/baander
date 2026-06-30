<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\JobStatus;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobMonitorFilter;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

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
        $repository->method('findOneBy')
            ->with(['jobId' => 'non-existent'])
            ->willReturn(null);

        $this->entityManager->method('getRepository')
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
        $repository->method('findOneBy')
            ->with(['jobId' => 'job-123'])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $result = $this->service->findByJobIdOrFail('job-123');

        $this->assertSame($entity, $result);
        $this->assertSame('job-123', $result->getJobId());
    }

    // ── markCancelled tests ──────────────────────────────────────────────

    public function testMarkCancelledSetsStatusToCancelled(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-cancel');
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')
            ->with(['jobId' => 'job-cancel'])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->markCancelled('job-cancel');

        $this->assertSame(JobStatus::Cancelled, $entity->getStatus());
        $this->assertNotNull($entity->getFinishedAt());
    }

    public function testMarkCancelledThrowsWhenNotFound(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->expectException(RuntimeException::class);

        $this->service->markCancelled('non-existent');
    }

    // ── appendAuditLog tests ─────────────────────────────────────────────

    public function testAppendAuditLogCreatesNewLogWhenNull(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-audit');
        $this->assertNull($entity->getAuditLog());

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')
            ->with(['jobId' => 'job-audit'])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $entry = ['action' => 'test', 'userId' => 'user-1'];
        $this->service->appendAuditLog('job-audit', $entry);

        $log = json_decode($entity->getAuditLog(), true);
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
        $repository->method('findOneBy')
            ->with(['jobId' => 'job-audit-2'])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $newEntry = ['action' => 'second', 'userId' => 'user-2'];
        $this->service->appendAuditLog('job-audit-2', $newEntry);

        $log = json_decode($entity->getAuditLog(), true);
        $this->assertCount(2, $log);
        $this->assertSame('first', $log[0]['action']);
        $this->assertSame('second', $log[1]['action']);
    }

    public function testAppendAuditLogThrowsWhenNotFound(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->method('getRepository')
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
        $repository->method('findOneBy')
            ->with(['jobId' => 'job-retry'])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->assertFalse($entity->isRetried());
        $this->assertNull($entity->getAuditLog());

        $this->service->markRetriedWithAudit('job-retry', 'new-job-456', 'user-99');

        $this->assertTrue($entity->isRetried());

        $log = json_decode($entity->getAuditLog(), true);
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
        $repository->method('findOneBy')
            ->with(['jobId' => 'job-retry-2'])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->markRetriedWithAudit('job-retry-2', 'new-job-789', 'user-42');

        $this->assertTrue($entity->isRetried());

        $log = json_decode($entity->getAuditLog(), true);
        $this->assertCount(2, $log);
        $this->assertSame('previous', $log[0]['action']);
        $this->assertSame('retry', $log[1]['action']);
    }

    public function testMarkRetriedWithAuditThrowsWhenNotFound(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(JobMonitorEntity::class)
            ->willReturn($repository);

        $this->expectException(RuntimeException::class);

        $this->service->markRetriedWithAudit('non-existent', 'new-job', 'user-1');
    }
}
