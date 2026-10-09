<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Interface\Resource\ScheduledJobResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** What the app:scheduler:* commands share: the job argument, the not-found outcome and the job's rendering. */
final class ScheduledJobConsole
{
    public const string ID_ARGUMENT = 'id';

    public static function addIdArgument(Command $command): void
    {
        $command->addArgument(self::ID_ARGUMENT, InputArgument::REQUIRED, 'UUID of the scheduled job, as app:scheduler:list prints it');
    }

    /** @throws InvalidInputException when the argument is not a UUID */
    public static function id(InputInterface $input): Uuid
    {
        return AdminCommandSupport::uuid($input->getArgument(self::ID_ARGUMENT), 'The job ID');
    }

    /** The outcome the admin API reports as 404 for the same job. */
    public static function notFound(): NotFoundException
    {
        return new NotFoundException('Scheduled job not found.');
    }

    /**
     * Prints a job's fields.
     *
     * @param array<string, mixed> $job a ScheduledJobResource
     */
    public static function show(SymfonyStyle $io, array $job): void
    {
        $io->definitionList(
            ['ID' => $job['id']],
            ['Name' => $job['name']],
            ['Description' => $job['description'] ?? '-'],
            ['Expression' => $job['expression']],
            ['Type' => $job['jobType']],
            ['Command' => $job['command']],
            ['Parameters' => json_encode($job['parameters'] === [] ? new \stdClass() : $job['parameters'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ['Status' => $job['status']],
            ['Last run' => $job['lastRunAt'] ?? '-'],
            ['Next run' => $job['nextRunAt'] ?? '-'],
            ['Last result' => $job['lastResult'] ?? '-'],
            ['Runs' => (string) $job['runCount']],
            ['Last failure' => $job['lastFailureAt'] ?? '-'],
            ['Last error' => $job['lastError'] ?? '-'],
            ['Created' => $job['createdAt']],
            ['Updated' => $job['updatedAt']],
        );
    }

    /**
     * Runs a status change on one job and reports it, like the scheduler page's row actions, or
     * with `--json` prints the changed job as the API returns it.
     *
     * @param \Closure(Uuid): ?object $change the administration port's action; null when the job does not exist
     * @param string                  $done   the action in the past tense, such as "Paused"
     */
    public static function changeStatus(InputInterface $input, SymfonyStyle $io, \Closure $change, string $done): int
    {
        try {
            $job = ScheduledJobResource::from($change(self::id($input)) ?? throw self::notFound());
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $job);
        }

        $io->success(sprintf('%s scheduled job "%s". Its status is %s.', $done, $job['name'], $job['status']));

        return Command::SUCCESS;
    }
}
