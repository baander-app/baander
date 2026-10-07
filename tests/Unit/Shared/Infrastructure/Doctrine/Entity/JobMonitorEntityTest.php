<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class JobMonitorEntityTest extends TestCase
{
    public function testNewEntityDefaultsToQueuedStatus(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');

        $this->assertSame(JobStatus::Queued, $entity->getStatus());
    }

    public function testNewEntitySetsQueuedAtToNonNullDateTimeImmutable(): void
    {
        $before = new \DateTimeImmutable();
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $after = new \DateTimeImmutable();

        $this->assertInstanceOf(\DateTimeImmutable::class, $entity->getQueuedAt());
        $this->assertGreaterThanOrEqual($before, $entity->getQueuedAt());
        $this->assertLessThanOrEqual($after, $entity->getQueuedAt());
    }

    public function testMarkStartedTransitionsQueuedToRunningAndSetsStartedAt(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');

        $entity->markStarted();

        $this->assertSame(JobStatus::Running, $entity->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $entity->getStartedAt());
    }

    public function testMarkFinishedTransitionsRunningToFinishedAndSetsFinishedAt(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $entity->markStarted();

        $entity->markFinished();

        $this->assertSame(JobStatus::Finished, $entity->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $entity->getFinishedAt());
    }

    public function testMarkFailedTransitionsRunningToFailedAndSetsFinishedAt(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $entity->markStarted();

        $entity->markFailed();

        $this->assertSame(JobStatus::Failed, $entity->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $entity->getFinishedAt());
    }

    public function testMarkStartedAfterAFailureStartsTheNextAttemptWithoutTheOldOutcome(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $entity->markStarted();
        $entity->setProgress(40);
        $entity->setException(['message' => 'First attempt failed.']);
        $entity->setExceptionClass(\RuntimeException::class);
        $entity->markFailed();

        $entity->markStarted();

        $this->assertSame(2, $entity->getAttempt());
        $this->assertSame(JobStatus::Running, $entity->getStatus());
        $this->assertNotNull($entity->getStartedAt());
        $this->assertNull($entity->getFinishedAt());
        $this->assertNull($entity->getProgress());
        $this->assertNull($entity->getException());
        $this->assertNull($entity->getExceptionClass());
    }

    public function testFinishIsNeverBeforeTheStart(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $entity->markStarted();
        $future = new \DateTimeImmutable('+1 hour');
        (new \ReflectionProperty(JobMonitorEntity::class, 'startedAt'))->setValue($entity, $future);

        $entity->markFinished();

        $this->assertEquals($future, $entity->getFinishedAt());
    }

    public function testMarkCancelledSetsStatusAndTimestamps(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $before = new \DateTimeImmutable();

        $entity->markCancelled();

        $after = new \DateTimeImmutable();

        $this->assertSame(JobStatus::Cancelled, $entity->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $entity->getFinishedAt());
        $this->assertGreaterThanOrEqual($before, $entity->getFinishedAt());
        $this->assertLessThanOrEqual($after, $entity->getFinishedAt());
        $this->assertGreaterThanOrEqual($before, $entity->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $entity->getUpdatedAt());
    }

    public function testDataTruncatedDefaultsToFalse(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');

        $this->assertFalse($entity->getDataTruncated());
    }

    public function testAuditLogDefaultsToNull(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');

        $this->assertNull($entity->getAuditLog());
    }

    public function testSetDataTruncatedUpdatesValueAndTimestamp(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $before = new \DateTimeImmutable();

        $entity->setDataTruncated(true);

        $this->assertTrue($entity->getDataTruncated());
        $this->assertGreaterThanOrEqual($before, $entity->getUpdatedAt());
    }

    public function testSetAuditLogUpdatesValueAndTimestamp(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $before = new \DateTimeImmutable();

        $entity->setAuditLog('[{"action":"retry","at":"2026-04-19T12:00:00+00:00"}]');

        $this->assertSame('[{"action":"retry","at":"2026-04-19T12:00:00+00:00"}]', $entity->getAuditLog());
        $this->assertGreaterThanOrEqual($before, $entity->getUpdatedAt());
    }

    public function testConstructorSetsAllOptionalParameters(): void
    {
        $entity = new JobMonitorEntity(
            jobId: 'job-456',
            jobUuid: null,
            name: 'extract:album-cover',
            queue: 'async',
        );

        $this->assertSame('job-456', $entity->getJobId());
        $this->assertNull($entity->getJobUuid());
        $this->assertSame('extract:album-cover', $entity->getName());
        $this->assertSame('async', $entity->getQueue());
    }

    public function testNewEntityHasNonNullCreatedAtAndUpdatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $entity = new JobMonitorEntity(jobId: 'job-123');
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $entity->getCreatedAt());
        $this->assertLessThanOrEqual($after, $entity->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $entity->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $entity->getUpdatedAt());
    }

    public function testNewEntityStartedAtAndFinishedAtAreNull(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');

        $this->assertNull($entity->getStartedAt());
        $this->assertNull($entity->getFinishedAt());
    }

    public function testNewEntityAttemptIsZeroAndNotRetried(): void
    {
        $entity = new JobMonitorEntity(jobId: 'job-123');

        $this->assertSame(0, $entity->getAttempt());
        $this->assertFalse($entity->isRetried());
    }
    public function testConstructorConvertsJobUuidToTheMappedValueType(): void
    {
        $uuid = Uuid::generate()->toString();

        $entity = new JobMonitorEntity('job-uuid', jobUuid: $uuid);

        $mappedValue = (new \ReflectionProperty(JobMonitorEntity::class, 'jobUuid'))->getValue($entity);
        self::assertInstanceOf(Uuid::class, $mappedValue);
        self::assertSame($uuid, $entity->getJobUuid());
        self::assertSame($uuid, (new UuidType())->convertToDatabaseValue($mappedValue, new PostgreSQLPlatform()));
    }

    public function testGetterSerializesTheUuidReturnedByTheDoctrineType(): void
    {
        $uuid = Uuid::generate()->toString();
        $mappedValue = (new UuidType())->convertToPHPValue($uuid, new PostgreSQLPlatform());
        $entity = new JobMonitorEntity('job-hydrated');
        (new \ReflectionProperty(JobMonitorEntity::class, 'jobUuid'))->setValue($entity, $mappedValue);

        self::assertSame($uuid, $entity->getJobUuid());
    }

    public function testConstructorRejectsAnInvalidJobUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"invalid-uuid" is not a valid UUID.');

        new JobMonitorEntity('job-invalid', jobUuid: 'invalid-uuid');
    }

}
