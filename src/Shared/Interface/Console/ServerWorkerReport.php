<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Prints the figures of per-worker diagnostics reads and names the workers a read could not cover. */
final class ServerWorkerReport
{
    /** A figure as a table cell: yes or no for a flag, the value for another scalar, otherwise "-". */
    public static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => AdminCommandSupport::yesNo($value),
            is_scalar($value) => (string) $value,
            default => '-',
        };
    }

    /**
     * Prints, on stderr, each worker that did not answer and each worker whose read failed.
     *
     * @param list<int> $missingWorkers
     * @param list<array{worker_id: int, error: string}> $workerErrors
     *
     * @return int SUCCESS when every worker answered, otherwise FAILURE
     */
    public static function finish(SymfonyStyle $io, array $missingWorkers, array $workerErrors): int
    {
        if ($missingWorkers === [] && $workerErrors === []) {
            return Command::SUCCESS;
        }

        $lines = [];
        if ($missingWorkers !== []) {
            $lines[] = sprintf('No answer from worker(s) %s.', implode(', ', $missingWorkers));
        }
        foreach ($workerErrors as $error) {
            $lines[] = sprintf('Worker %d failed: %s', $error['worker_id'], $error['error']);
        }
        $io->getErrorStyle()->warning($lines);

        return Command::FAILURE;
    }
}
