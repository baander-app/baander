<?php

declare(strict_types=1);

namespace App\Scheduler\Application\CommandHandler;

use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Scheduler\Application\Port\SchedulerOccurrenceExecutionStoreInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExecuteScheduledOccurrenceHandler
{
    public function __construct(
        private SchedulerOccurrenceExecutionStoreInterface $executions,
        private ExecuteScheduledJobHandler $jobs,
    ) {}

    public function __invoke(ExecuteScheduledOccurrenceCommand $command): void
    {
        $attemptId = Uuid::generate();
        $occurrence = $this->executions->begin($command->occurrenceId, $attemptId);
        if ($occurrence === null) {
            return;
        }

        $this->jobs->executeOccurrence($occurrence);
        if (!$this->executions->markReturned($command->occurrenceId, $attemptId)) {
            throw new \RuntimeException('Scheduled occurrence return receipt was not accepted.');
        }
    }
}
