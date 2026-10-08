<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Infrastructure\Service;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Scheduler\Application\Exception\InvalidScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Service\ScheduledJobService;
use PHPUnit\Framework\TestCase;

/**
 * Confirms ScheduledJobService stores jobs without validating that the command
 * is registered or that the supplied parameters match the command's schema.
 */
final class ScheduledJobServiceValidationTest extends TestCase
{
    public function testCreateRejectsUnregisteredMessengerCommand(): void
    {
        $repository = $this->createStub(ScheduledJobRepositoryInterface::class);
        $registry = $this->createStub(SchedulerRegistry::class);
        $registry->method('isMessengerCommandAllowed')->willReturn(false);
        $service = new ScheduledJobService($repository, $registry);

        $this->expectException(InvalidScheduledJob::class);
        $this->expectExceptionMessage('not registered');

        $service->create(
            name: 'Rogue Job',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: 'App\\Scheduler\\Nonexistent\\RogueCommand',
        );
    }

    public function testCreateRejectsParametersNotInAllowedSchema(): void
    {
        $repository = $this->createStub(ScheduledJobRepositoryInterface::class);
        $registry = $this->createStub(SchedulerRegistry::class);
        $registry->method('isMessengerCommandAllowed')->willReturnCallback(
            static fn (string $command): bool => $command === BatchExtractCoversCommand::class,
        );
        $registry->method('getMessengerParameterSchema')->willReturn([]);
        $service = new ScheduledJobService($repository, $registry);

        // BatchExtractCoversCommand declares an empty parameter schema, so any
        // supplied parameter is invalid. The service should reject this.
        $this->expectException(InvalidScheduledJob::class);
        $this->expectExceptionMessage('parameters');

        $service->create(
            name: 'Cover extraction with unexpected params',
            expression: '0 0 * * *',
            jobType: JobType::Messenger,
            command: BatchExtractCoversCommand::class,
            parameters: ['libraryId' => '550e8400-e29b-41d4-a716-446655440000'],
        );
    }
}
