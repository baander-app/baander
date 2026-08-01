<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'baander:lyrics:fetch',
    description: 'Bulk-fetch lyrics from LRCLIB for songs that are missing them.',
)]
final class FetchLyricsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of songs to process', '100')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between fetches in milliseconds', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        $delay = (int) $input->getOption('delay');

        if ($limit < 1) {
            $io->error('Limit must be a positive integer.');

            return Command::FAILURE;
        }

        if ($delay < 0) {
            $io->error('Delay must be a non-negative integer.');

            return Command::FAILURE;
        }

        $io->info(sprintf(
            'Starting bulk lyrics fetch (limit: %d, delay: %dms).',
            $limit,
            $delay,
        ));

        try {
            $envelope = $this->commandBus->dispatch(new BulkFetchLyricsCommand(
                limit: $limit,
                delayMs: $delay,
            ));

            $dispatched = $envelope->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $e) {
            $io->error('Bulk lyrics fetch failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if (!is_int($dispatched) || $dispatched === 0) {
            $io->info('No songs required lyrics fetching. All songs already have lyrics.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Dispatched lyrics fetch for %d song(s).', $dispatched));

        return Command::SUCCESS;
    }
}
