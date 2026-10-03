<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Internal insert primitive; its caller owns commit/rollback and any cursor update. */
#[Exclude]
final readonly class TransactionalSchedulerOccurrenceWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(SchedulerOccurrence $occurrence): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Scheduler intent insertion requires an active caller transaction.');
        }
        $parameters = [
            'id' => $occurrence->id->toString(), 'job' => $occurrence->jobId->toString(),
            'scheduled' => $occurrence->scheduledFor->format('Y-m-d H:i:s.uP'),
            'type' => $occurrence->jobType->value, 'command' => $occurrence->command,
            'parameters' => $occurrence->parametersJson(),
        ];
        $inserted = $this->connection->executeStatement(<<<'SQL'
            INSERT INTO scheduler_occurrences (id, job_id, scheduled_for, job_type, command, parameters)
            VALUES (:id, :job, :scheduled, :type, :command, CAST(:parameters AS JSON))
            ON CONFLICT DO NOTHING
            SQL, $parameters);
        if ($inserted === 1) {
            return true;
        }
        if ($this->connection->fetchOne('SELECT 1 FROM scheduler_occurrences WHERE id = :id AND (job_id <> :job OR scheduled_for <> :scheduled)', ['id' => $parameters['id'], 'job' => $parameters['job'], 'scheduled' => $parameters['scheduled']]) !== false) {
            throw new \LogicException('Scheduler occurrence identifier is already assigned to another slot.');
        }
        $row = $this->connection->fetchAssociative('SELECT job_type, command, parameters FROM scheduler_occurrences WHERE job_id = :job AND scheduled_for = :scheduled', ['job' => $parameters['job'], 'scheduled' => $parameters['scheduled']]);
        if ($row === false || $row['job_type'] !== $parameters['type'] || $row['command'] !== $parameters['command']) {
            throw new \LogicException('Scheduler occurrence slot already has a different immutable snapshot.');
        }
        $decoded = json_decode($row['parameters'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 8) !== $parameters['parameters']) {
            throw new \LogicException('Scheduler occurrence slot already has a different immutable snapshot.');
        }
        // An identical retry preserves the originally admitted ID and dispatch/execution state.
        return false;
    }
}
