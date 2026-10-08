<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Names the workers a per-worker diagnostics read could not cover. */
final class ServerWorkerReport
{
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
