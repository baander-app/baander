<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\Console;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Exception\SchedulerOccurrenceConflict;
use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Interface\Console\SchedulerRunCommand;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerRunCommandTest extends TestCase
{
    public function testProvidedRequestIdIsPreservedAndAcceptedWithoutDispatch(): void
    {
        $jobId = new \App\Shared\Domain\Model\PublicId();
        $requestId = Uuid::generate();
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('recordByPublicId')->with($jobId, $requestId)->willReturn(
            new SchedulerOccurrence($requestId, Uuid::generate(), new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], origin: SchedulerOccurrenceOrigin::Manual, jobPublicId: $jobId),
        );
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $jobId->toString(), '--request-id' => $requestId->toString()]));
        self::assertStringContainsString('Request UUID: ' . $requestId->toString(), $tester->getDisplay());
        self::assertStringContainsString('Accepted occurrence ', $tester->getDisplay());
        self::assertStringNotContainsString('Accepted occurrence ' . $requestId->toString(), $tester->getDisplay());
    }

    public function testJsonPrintsTheApiPayloadAndTheRequestUuidOnStderr(): void
    {
        $jobId = new \App\Shared\Domain\Model\PublicId();
        $requestId = Uuid::generate();
        $recorder = $this->createStub(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->method('recordByPublicId')->willReturn(
            new SchedulerOccurrence($requestId, Uuid::generate(), new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], origin: SchedulerOccurrenceOrigin::Manual, jobPublicId: $jobId),
        );
        $tester = new CommandTester(new SchedulerRunCommand($recorder));

        self::assertSame(Command::SUCCESS, $tester->execute(
            ['id' => $jobId->toString(), '--request-id' => $requestId->toString(), '--json' => true],
            ['capture_stderr_separately' => true],
        ));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotSame($requestId->toString(), $decoded['occurrenceId']);
        self::assertSame(
            ['occurrenceId' => $decoded['occurrenceId'], 'jobId' => $jobId->toString()],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('Request UUID: ' . $requestId->toString(), $tester->getErrorOutput());
    }

    public function testJsonPrintsAMissingJobOnStderrOnly(): void
    {
        $recorder = $this->createStub(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->method('recordByPublicId')->willReturn(null);
        $tester = new CommandTester(new SchedulerRunCommand($recorder));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => (new \App\Shared\Domain\Model\PublicId())->toString(), '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('not found', $tester->getErrorOutput());
    }

    public function testGeneratedIdentityIsPrintedBeforeAnUncertainCommit(): void
    {
        $jobId = new \App\Shared\Domain\Model\PublicId();
        $requestId = null;
        $error = new \RuntimeException('Commit acknowledgement lost');
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        $recorder->expects(self::once())->method('recordByPublicId')->willReturnCallback(function (\App\Shared\Domain\Model\PublicId $id, Uuid $generated) use (&$requestId, $tester, $error): never {
            $requestId = $generated;
            self::assertSame('7', $generated->toString()[14]);
            self::assertStringContainsString('Request UUID: ' . $generated->toString(), $tester->getDisplay());
            throw $error;
        });
        try {
            $tester->execute(['id' => $jobId->toString()]);
            self::fail('An uncertain commit must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($error, $actual);
        }
        self::assertInstanceOf(Uuid::class, $requestId);
        self::assertStringNotContainsString('Accepted occurrence', $tester->getDisplay());
    }

    public function testInvalidIdentityDoesNotRecord(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::never())->method('recordByPublicId');
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::INVALID, $tester->execute(['id' => (new \App\Shared\Domain\Model\PublicId())->toString(), '--request-id' => 'invalid']));
    }

    public function testRequestIdentityCollisionReportsFailureWithoutAcceptance(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('recordByPublicId')->willThrowException(new SchedulerOccurrenceConflict('Different job'));
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::FAILURE, $tester->execute(['id' => (new \App\Shared\Domain\Model\PublicId())->toString(), '--request-id' => Uuid::generate()->toString()]));
        self::assertStringContainsString('Request UUID already belongs to another job or origin.', $tester->getDisplay());
        self::assertStringNotContainsString('Accepted occurrence', $tester->getDisplay());
    }

    #[DataProvider('nonConflictFailures')]
    public function testRecorderFailurePropagatesWithoutReportingIdentityConflict(\LogicException $error): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('recordByPublicId')->willThrowException($error);
        $requestId = Uuid::generate();
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        try {
            $tester->execute(['id' => (new \App\Shared\Domain\Model\PublicId())->toString(), '--request-id' => $requestId->toString()]);
            self::fail('A recorder failure must propagate.');
        } catch (\LogicException $actual) {
            self::assertSame($error, $actual);
        }
        self::assertStringContainsString('Request UUID: ' . $requestId->toString(), $tester->getDisplay());
        self::assertStringNotContainsString('already belongs', $tester->getDisplay());
        self::assertStringNotContainsString('Accepted occurrence', $tester->getDisplay());
    }

    /** @return iterable<string, array{\LogicException}> */
    public static function nonConflictFailures(): iterable
    {
        yield 'invalid stored snapshot' => [new \InvalidArgumentException('Scheduler occurrence parameter nesting exceeds eight arrays.')];
        yield 'ambient transaction guard' => [new \LogicException('Scheduler manual recording requires a dedicated idle autocommit connection.')];
    }

    public function testMissingJobDoesNotReportAcceptance(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('recordByPublicId')->willReturn(null);
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::FAILURE, $tester->execute(['id' => (new \App\Shared\Domain\Model\PublicId())->toString()]));
        self::assertStringNotContainsString('Accepted occurrence', $tester->getDisplay());
    }
}
