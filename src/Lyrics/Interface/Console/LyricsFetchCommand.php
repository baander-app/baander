<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/lyrics/bulk-fetch.
 *
 * Runs BulkFetchLyricsCommand in this process and records the run in the job monitor. The
 * run queues one fetch per song on the async transport; the workers' consumer performs the
 * fetches, which only call LRCLIB and write to the database.
 */
#[AsCommand(
    name: 'app:lyrics:fetch',
    description: 'Queue a lyrics fetch from LRCLIB for every song without lyrics, or up to --limit songs.',
)]
final class LyricsFetchCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Most songs to queue; leave it out to queue every song without lyrics')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Milliseconds between the queued fetches', (string) BulkFetchLyricsCommand::DEFAULT_DELAY_MS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $limit = $input->getOption('limit') === null ? null : self::integer($input, 'limit');
            $delay = self::integer($input, 'delay');

            $io->text(sprintf(
                'Queuing lyrics fetches for %s, %d ms apart...',
                $limit === null ? 'every song without lyrics' : sprintf('up to %d songs without lyrics', $limit),
                $delay,
            ));
            $run = $this->jobs->runInline(new BulkFetchLyricsCommand(limit: $limit, delayMs: $delay));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $queued = is_int($run->result) ? $run->result : 0;
        if ($queued === 0) {
            $io->success(sprintf('No song needs lyrics; nothing was queued. Job ID: %s', $run->jobId));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Queued %d lyrics fetch(es). The workers fetch them from LRCLIB over the next %s. Job ID: %s',
            $queued,
            self::duration(($queued - 1) * $delay),
            $run->jobId,
        ));

        return Command::SUCCESS;
    }

    private static function integer(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getOption($name), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new InvalidInputException(sprintf('--%s must be an integer.', $name));
        }

        return $value;
    }

    private static function duration(int $milliseconds): string
    {
        $seconds = intdiv($milliseconds, 1000);

        return match (true) {
            $seconds < 60 => sprintf('%d s', $seconds),
            $seconds < 3600 => sprintf('%d min', (int) ceil($seconds / 60)),
            default => sprintf('%.1f h', $seconds / 3600),
        };
    }
}
