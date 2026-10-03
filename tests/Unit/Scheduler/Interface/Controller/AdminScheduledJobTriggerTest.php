<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\Controller;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Exception\SchedulerOccurrenceConflict;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Interface\Controller\AdminScheduledJobController;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminScheduledJobTriggerTest extends TestCase
{
    public function testRetryReturnsDurableReceiptWithoutReadingPossiblyDeletedJob(): void
    {
        $jobId = Uuid::generate();
        $requestId = Uuid::generate();
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::never())->method('getById');
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::exactly(2))->method('record')->with($jobId, $requestId)->willReturn(
            new SchedulerOccurrence($requestId, $jobId, new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], SchedulerOccurrenceOrigin::Manual),
        );
        $controller = new AdminScheduledJobController($jobs, new SchedulerRegistry([], []), $recorder);
        $request = new Request();
        $request->headers->set('Idempotency-Key', $requestId->toString());
        foreach ([1, 2] as $attempt) {
            $response = $controller->trigger($jobId->toString(), $request);
            self::assertSame(202, $response->getStatusCode());
            self::assertSame($requestId->toString(), $response->headers->get('Idempotency-Key'));
            self::assertSame(['data' => ['occurrenceId' => $requestId->toString(), 'jobId' => $jobId->toString()]], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function testMissingHeaderGeneratesAndReturnsUuidV7Identity(): void
    {
        $jobId = Uuid::generate();
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willReturnCallback(function (Uuid $id, Uuid $requestId) use ($jobId): SchedulerOccurrence {
            self::assertTrue($id->equals($jobId));
            self::assertSame('7', $requestId->toString()[14]);
            return new SchedulerOccurrence($requestId, $id, new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], SchedulerOccurrenceOrigin::Manual);
        });
        $controller = new AdminScheduledJobController($this->createStub(ScheduledJobPortInterface::class), new SchedulerRegistry([], []), $recorder);
        $response = $controller->trigger($jobId->toString(), new Request());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame($data['data']['occurrenceId'], $response->headers->get('Idempotency-Key'));
    }

    public function testMalformedKeyIsRejectedBeforeRecording(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $controller = new AdminScheduledJobController($this->createStub(ScheduledJobPortInterface::class), new SchedulerRegistry([], []), $recorder);
        $request = new Request();
        $request->headers->set('Idempotency-Key', 'invalid');
        self::assertSame(400, $controller->trigger(Uuid::generate()->toString(), $request)->getStatusCode());
    }

    public function testRequestIdentityCollisionReturnsConflict(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willThrowException(new SchedulerOccurrenceConflict('Different job'));
        $controller = new AdminScheduledJobController($this->createStub(ScheduledJobPortInterface::class), new SchedulerRegistry([], []), $recorder);
        $request = new Request();
        $request->headers->set('Idempotency-Key', Uuid::generate()->toString());
        self::assertSame(409, $controller->trigger(Uuid::generate()->toString(), $request)->getStatusCode());
    }

    #[DataProvider('nonConflictFailures')]
    public function testRecorderFailureIsNotReportedAsIdentityConflict(\LogicException $error): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willThrowException($error);
        $controller = new AdminScheduledJobController($this->createStub(ScheduledJobPortInterface::class), new SchedulerRegistry([], []), $recorder);
        $request = new Request();
        $request->headers->set('Idempotency-Key', Uuid::generate()->toString());
        $this->expectExceptionObject($error);
        $controller->trigger(Uuid::generate()->toString(), $request);
    }

    /** @return iterable<string, array{\LogicException}> */
    public static function nonConflictFailures(): iterable
    {
        yield 'invalid stored snapshot' => [new \InvalidArgumentException('Scheduler occurrence parameter nesting exceeds eight arrays.')];
        yield 'ambient transaction guard' => [new \LogicException('Scheduler manual recording requires a dedicated idle autocommit connection.')];
    }

    public function testNewRequestForMissingJobReturnsNotFound(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willReturn(null);
        $controller = new AdminScheduledJobController($this->createStub(ScheduledJobPortInterface::class), new SchedulerRegistry([], []), $recorder);
        self::assertSame(404, $controller->trigger(Uuid::generate()->toString(), new Request())->getStatusCode());
    }

    public function testUncertainCommitCannotReturnAnAcceptanceReceipt(): void
    {
        $error = new \RuntimeException('Commit acknowledgement lost');
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willThrowException($error);
        $controller = new AdminScheduledJobController($this->createStub(ScheduledJobPortInterface::class), new SchedulerRegistry([], []), $recorder);
        $this->expectExceptionObject($error);
        $controller->trigger(Uuid::generate()->toString(), new Request());
    }
}
