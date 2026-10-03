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
        $jobId = Uuid::generate();
        $requestId = Uuid::generate();
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with($jobId, $requestId)->willReturn(
            new SchedulerOccurrence($requestId, $jobId, new \DateTimeImmutable('2026-10-03T10:00:00Z'), JobType::Console, 'app:test', [], SchedulerOccurrenceOrigin::Manual),
        );
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $jobId->toString(), '--request-id' => $requestId->toString()]));
        self::assertStringContainsString('Request UUID: ' . $requestId->toString(), $tester->getDisplay());
        self::assertStringContainsString('Accepted occurrence ' . $requestId->toString(), $tester->getDisplay());
    }

    public function testGeneratedIdentityIsPrintedBeforeAnUncertainCommit(): void
    {
        $jobId = Uuid::generate();
        $requestId = null;
        $error = new \RuntimeException('Commit acknowledgement lost');
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        $recorder->expects(self::once())->method('record')->willReturnCallback(function (Uuid $id, Uuid $generated) use (&$requestId, $tester, $error): never {
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
        $recorder->expects(self::never())->method('record');
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::INVALID, $tester->execute(['id' => Uuid::generate()->toString(), '--request-id' => 'invalid']));
    }

    public function testRequestIdentityCollisionReportsFailureWithoutAcceptance(): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willThrowException(new SchedulerOccurrenceConflict('Different job'));
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::FAILURE, $tester->execute(['id' => Uuid::generate()->toString(), '--request-id' => Uuid::generate()->toString()]));
        self::assertStringContainsString('Request UUID already belongs to another job or origin.', $tester->getDisplay());
        self::assertStringNotContainsString('Accepted occurrence', $tester->getDisplay());
    }

    #[DataProvider('nonConflictFailures')]
    public function testRecorderFailurePropagatesWithoutReportingIdentityConflict(\LogicException $error): void
    {
        $recorder = $this->createMock(SchedulerManualOccurrenceRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willThrowException($error);
        $requestId = Uuid::generate();
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        try {
            $tester->execute(['id' => Uuid::generate()->toString(), '--request-id' => $requestId->toString()]);
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
        $recorder->expects(self::once())->method('record')->willReturn(null);
        $tester = new CommandTester(new SchedulerRunCommand($recorder));
        self::assertSame(Command::FAILURE, $tester->execute(['id' => Uuid::generate()->toString()]));
        self::assertStringNotContainsString('Accepted occurrence', $tester->getDisplay());
    }
}
