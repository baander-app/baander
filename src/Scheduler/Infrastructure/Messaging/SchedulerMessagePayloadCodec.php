<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Messaging;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class SchedulerMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'scheduler.execute_job' => ['job_id', 'job_type', 'command', 'parameters'],
        'scheduler.execute_occurrence' => ['occurrence_id'],
    ];

    public function types(): array
    {
        return [
            'scheduler.execute_job' => ExecuteScheduledJobCommand::class,
            'scheduler.execute_occurrence' => ExecuteScheduledOccurrenceCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof ExecuteScheduledJobCommand => [$message->jobId, $message->jobType, $message->command, $message->parameters],
            $message instanceof ExecuteScheduledOccurrenceCommand => [$message->occurrenceId->toString()],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'scheduler.execute_job' => new ExecuteScheduledJobCommand($p['job_id'], $p['job_type'], $p['command'], $p['parameters']),
            'scheduler.execute_occurrence' => new ExecuteScheduledOccurrenceCommand(Uuid::fromString($p['occurrence_id'])),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
