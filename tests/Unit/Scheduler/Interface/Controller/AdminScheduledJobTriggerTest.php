<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\Controller;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Exception\SchedulerOccurrenceConflict;
use App\Scheduler\Application\Port\ScheduledJobAdministrationInterface;
use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Interface\Controller\AdminScheduledJobController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminScheduledJobTriggerTest extends TestCase
{
    public function testRetryReturnsDurableReceiptWithoutReadingPossiblyDeletedJob(): void
    {
        $job = new PublicId();
        $requestId = Uuid::generate();
        $occurrence = new SchedulerOccurrence($requestId, Uuid::generate(), new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], origin: SchedulerOccurrenceOrigin::Manual, jobPublicId: $job);
        $jobs = $this->createMock(ScheduledJobAdministrationInterface::class);
        $jobs->expects(self::never())->method('getByPublicId');
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::exactly(2))->method('recordByPublicId')->with($job, $requestId)->willReturn($occurrence);
        $controller = new AdminScheduledJobController($jobs, $recorder);
        $request = new Request();
        $request->headers->set('Idempotency-Key', $requestId->toString());
        foreach ([1, 2] as $attempt) {
            $response = $controller->trigger($job->toString(), $request);
            self::assertSame(202, $response->getStatusCode());
            self::assertSame($requestId->toString(), $response->headers->get('Idempotency-Key'));
            $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['data' => ['occurrenceId' => $occurrence->publicId->toString(), 'jobId' => $job->toString()]], $body);
            self::assertStringNotContainsString($occurrence->id->toString(), (string) $response->getContent());
            self::assertStringNotContainsString($occurrence->jobId->toString(), (string) $response->getContent());
        }
    }

    public function testMissingHeaderGeneratesAndReturnsUuidV7Identity(): void
    {
        $job = new PublicId();
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('recordByPublicId')->willReturnCallback(static function (PublicId $publicId, Uuid $requestId) use ($job): SchedulerOccurrence {
            self::assertTrue($publicId->equals($job));
            self::assertSame('7', $requestId->toString()[14]);
            return new SchedulerOccurrence($requestId, Uuid::generate(), new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], origin: SchedulerOccurrenceOrigin::Manual, jobPublicId: $job);
        });
        $response = (new AdminScheduledJobController($this->createStub(ScheduledJobAdministrationInterface::class), $recorder))->trigger($job->toString(), new Request());
        self::assertSame(202, $response->getStatusCode());
        self::assertNotSame(json_decode((string) $response->getContent(), true)['data']['occurrenceId'], $response->headers->get('Idempotency-Key'));
    }

    public function testInternalJobUuidIsRejectedBeforeRecording(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::never())->method('recordByPublicId');
        self::assertSame(400, (new AdminScheduledJobController($this->createStub(ScheduledJobAdministrationInterface::class), $recorder))->trigger(Uuid::generate()->toString(), new Request())->getStatusCode());
    }

    public function testRequestIdentityCollisionReturnsConflict(): void
    {
        $recorder = $this->createStub(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->method('recordByPublicId')->willThrowException(new SchedulerOccurrenceConflict('Different job'));
        self::assertSame(409, (new AdminScheduledJobController($this->createStub(ScheduledJobAdministrationInterface::class), $recorder))->trigger((new PublicId())->toString(), new Request())->getStatusCode());
    }

    public function testNewRequestForMissingJobReturnsNotFound(): void
    {
        $recorder = $this->createStub(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->method('recordByPublicId')->willReturn(null);
        self::assertSame(404, (new AdminScheduledJobController($this->createStub(ScheduledJobAdministrationInterface::class), $recorder))->trigger((new PublicId())->toString(), new Request())->getStatusCode());
    }

    public function testUncertainCommitCannotReturnAnAcceptanceReceipt(): void
    {
        $recorder = $this->createStub(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->method('recordByPublicId')->willThrowException(new \RuntimeException('Commit acknowledgement lost'));
        $this->expectException(\RuntimeException::class);
        (new AdminScheduledJobController($this->createStub(ScheduledJobAdministrationInterface::class), $recorder))->trigger((new PublicId())->toString(), new Request());
    }
}
