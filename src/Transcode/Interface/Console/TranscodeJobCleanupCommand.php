<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/transcode/jobs/cleanup. */
#[AsCommand(
    name: 'app:transcode:job:cleanup',
    description: 'Remove transcode jobs that no session uses, with their output.',
)]
final class TranscodeJobCleanupCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $cleaned = $this->support->dispatch(new CleanupOrphanedJobsCommand());
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $cleaned = is_int($cleaned) ? $cleaned : 0;
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, ['cleaned' => $cleaned]);
        }

        $io->success(sprintf('Removed %d orphaned transcode jobs.', $cleaned));

        return Command::SUCCESS;
    }
}
